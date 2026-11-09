<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\LiveState;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Resuelve la PLACA legible de un vehículo a partir de su id (UUID de `fleet_vehicles`) para enriquecer
 * el fan-out en vivo de telemetría.
 *
 * Lectura CROSS-CONTEXT read-only (patrón api-readmodel-enrichment): se lee la tabla del contexto fleet
 * con `DB::table` — sin dependencia composer ni FK dura entre contextos, coherente con la meta de que
 * cada contexto sea extraíble a microservicio. La resolución es EN LOTE (`whereIn`) y memoizada por
 * proceso para evitar el N+1 cuando se difunden muchos vehículos. Degrada con gracia: si la tabla no
 * existe o falla, devuelve null (el broadcast viaja sin placa, no rompe).
 */
final class FleetPlateResolver
{
    /** @var array<string, string|null> Mapa memoizado id→placa (null = buscado y no encontrado). */
    private array $cache = [];

    /** @var array<string, string|null> Mapa memoizado id→nodo base (null = buscado y no encontrado). */
    private array $nodeCache = [];

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
     * evita que cada espectador reciba el stream global completo. Se resuelve en la MISMA query en lote que
     * la placa (sin consulta extra) y es memoizado por proceso.
     */
    public function homeBaseNode(string $vehicleId): ?string
    {
        $this->labels([$vehicleId]);

        return $this->nodeCache[$vehicleId] ?? null;
    }

    /**
     * Placas de un lote de vehículos (id→placa|null), resolviendo en una sola query lo aún no memoizado.
     * De paso memoiza el nodo base de cada vehículo (misma fila), para el canal acotado por nodo.
     *
     * @param  list<string>               $vehicleIds
     * @return array<string, string|null>
     */
    public function labels(array $vehicleIds): array
    {
        $missing = array_values(array_filter(
            array_unique($vehicleIds),
            fn (string $id): bool => !array_key_exists($id, $this->cache),
        ));

        if ($missing !== []) {
            try {
                $rows = DB::table('fleet_vehicles')
                    ->whereIn('id', $missing)
                    ->get(['id', 'plate', 'home_base_node_id']);

                $byId = [];
                foreach ($rows as $row) {
                    $byId[(string) $row->id] = $row;
                }

                foreach ($missing as $id) {
                    $row = $byId[$id] ?? null;
                    $this->cache[$id] = $row !== null ? (string) $row->plate : null;
                    $this->nodeCache[$id] = ($row !== null && $row->home_base_node_id !== null)
                        ? (string) $row->home_base_node_id
                        : null;
                }
            } catch (Throwable) {
                // Degradación con gracia: sin placa/nodo, el fan-out sigue con el UUID y sin canal acotado.
                foreach ($missing as $id) {
                    $this->cache[$id] ??= null;
                    $this->nodeCache[$id] ??= null;
                }
            }
        }

        $result = [];
        foreach ($vehicleIds as $id) {
            $result[$id] = $this->cache[$id] ?? null;
        }

        return $result;
    }
}
