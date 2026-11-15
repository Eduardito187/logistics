<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\LiveState;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Logistics\Telemetry\Infrastructure\CrossContext\SafeRead;
use stdClass;
use Throwable;

/**
 * Resuelve la PLACA legible de un vehículo a partir de su id (UUID de `fleet_vehicles`) para enriquecer
 * el fan-out en vivo de telemetría.
 *
 * Lectura CROSS-CONTEXT read-only (patrón api-readmodel-enrichment): se lee la tabla del contexto fleet
 * con `DB::table` — sin dependencia composer ni FK dura entre contextos, coherente con la meta de que
 * cada contexto sea extraíble a microservicio. La resolución es EN LOTE (`whereIn`) y va por CACHÉ CORTA
 * (`live.reference_ttl_seconds`, el mismo criterio que LiveFleetViewFactory) en forma primitiva (arrays).
 *
 * Sin estado en el proceso: no hay estáticas ni propiedades memoizadoras, así que con workers persistentes
 * (Octane, colas) una placa o una sede que cambia en flota se refleja al vencer la caché, no al reiniciar el
 * worker. Degrada con gracia: si la caché no responde se lee directo de la base; si la tabla no existe o
 * falla, devuelve null (el broadcast viaja sin placa, no rompe) y ese fallo NO se cachea.
 *
 * @phpstan-type PlateReference array{plate: string|null, node: string|null}
 */
final class FleetPlateResolver
{
    /** Prefijo de la referencia del vehículo para el evento del admin (placa + nodo base). */
    private const string KEY = 'telemetry:live:ref:plate:';

    /**
     * Placa de un vehículo por su id, o null si no existe / no se pudo resolver.
     */
    public function label(string $vehicleId): ?string
    {
        return $this->labels([$vehicleId])[$vehicleId] ?? null;
    }

    /**
     * Nodo base (home_base_node_id) de un vehículo por su id, o null si no existe / no se pudo resolver.
     *
     * Se usa para acotar el fan-out en vivo a un canal por nodo (`fleet.node.{id}`): un nodo pertenece a un
     * solo departamento, así que escalar por nodo es coherente con la regla de ruta de un departamento y
     * evita que cada espectador reciba el stream global completo. Sale de la MISMA referencia en caché que
     * la placa (sin consulta extra).
     */
    public function homeBaseNode(string $vehicleId): ?string
    {
        return $this->references([$vehicleId])[$vehicleId]['node'] ?? null;
    }

    /**
     * Placas de un lote de vehículos (id→placa|null), resolviendo en una sola query lo que no está en caché.
     *
     * @param  list<string>               $vehicleIds
     * @return array<string, string|null>
     */
    public function labels(array $vehicleIds): array
    {
        $references = $this->references($vehicleIds);

        $result = [];
        foreach ($vehicleIds as $id) {
            $result[$id] = $references[$id]['plate'] ?? null;
        }

        return $result;
    }

    /**
     * Olvida la referencia en caché de un vehículo: la próxima lectura va a la base.
     */
    public function forget(string $vehicleId): void
    {
        try {
            $this->store()->forget(self::KEY.$vehicleId);
        } catch (Throwable) {
            // Vence sola con su TTL.
        }
    }

    /**
     * Referencia (placa + nodo base) de cada vehículo pedido: primero la caché corta, después UNA query en
     * lote por lo que falte. Lo "buscado y no encontrado" también se cachea (ambos null); un fallo de la
     * base, no.
     *
     * @param  list<string>                  $vehicleIds
     * @return array<string, PlateReference>
     */
    private function references(array $vehicleIds): array
    {
        $ids = array_values(array_unique($vehicleIds));
        if ($ids === []) {
            return [];
        }

        $found = $this->cached($ids);
        $missing = array_values(array_diff($ids, array_keys($found)));
        if ($missing === []) {
            return $found;
        }

        // Solo ids con forma de UUID llegan a la columna uuid; la lectura va protegida (savepoint si hay
        // transacción abierta) para que un fallo no aborte la transacción de la ingesta.
        $connection = DB::connection();
        $uuids = SafeRead::uuids($missing);

        /** @var list<stdClass>|null $rows */
        $rows = $uuids === [] ? [] : SafeRead::run(
            $connection,
            static fn (): array => $connection->table('fleet_vehicles')
                ->whereIn('id', $uuids)
                ->get(['id', 'plate', 'home_base_node_id'])
                ->all(),
            null,
        );

        if ($rows === null) {
            // Degradación con gracia: sin placa/nodo, el fan-out sigue con el UUID y sin canal acotado.
            return $found;
        }

        $fresh = array_fill_keys($missing, ['plate' => null, 'node' => null]);
        foreach ($rows as $row) {
            $fresh[(string) $row->id] = [
                'plate' => $row->plate !== null ? (string) $row->plate : null,
                'node' => $row->home_base_node_id !== null ? (string) $row->home_base_node_id : null,
            ];
        }

        $this->remember($fresh);

        return $found + $fresh;
    }

    /**
     * Referencias que ya están en la caché corta. Si el store no responde, ninguna (se leen de la base).
     *
     * @param  list<string>                  $ids
     * @return array<string, PlateReference>
     */
    private function cached(array $ids): array
    {
        $found = [];

        try {
            $store = $this->store();
            foreach ($ids as $id) {
                $hit = $store->get(self::KEY.$id);
                if (is_array($hit)) {
                    $plate = $hit['plate'] ?? null;
                    $node = $hit['node'] ?? null;
                    $found[$id] = [
                        'plate' => is_string($plate) ? $plate : null,
                        'node' => is_string($node) ? $node : null,
                    ];
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $found;
    }

    /**
     * Guarda las referencias recién leídas (forma primitiva) con el TTL corto.
     *
     * @param array<string, PlateReference> $references
     */
    private function remember(array $references): void
    {
        try {
            $store = $this->store();
            $ttl = max(1, (int) config('logistics-telemetry.live.reference_ttl_seconds', 60));
            foreach ($references as $id => $reference) {
                $store->put(self::KEY.$id, $reference, $ttl);
            }
        } catch (Throwable) {
            // La caché es un acelerador: sin ella la referencia se lee de la base en cada lote.
        }
    }

    /** El mismo store del estado caliente (Valkey por defecto). */
    private function store(): CacheRepository
    {
        /** @var string $store */
        $store = config('logistics-telemetry.live.cache_store', 'redis');

        return Cache::store($store);
    }
}
