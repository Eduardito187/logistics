<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\ReadSide;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Logistics\Carriers\Application\Contract\CarrierCorridorReader;
use Logistics\Carriers\Application\ReadModel\CarrierCorridorView;
use Logistics\Carriers\Application\Service\CorridorLabel;

/**
 * Lector SQL de los corredores tarifados (CQRS, lado de lectura).
 *
 * Número FIJO de consultas por página (sin N+1): corredores distintos de la página (GROUP BY + LIMIT/
 * OFFSET) → clases de vehículo de esos corredores → nombres de nodos de network en lote.
 *
 * Etiquetas: el vocabulario de tarifas es de SLUGS (`cd-santa-cruz`), no de códigos de nodo
 * (`CD-SCZ-01`). Si el slug coincide con un código de nodo (sin distinguir mayúsculas) o con un id de
 * nodo, se usa el nombre del nodo; si no, el slug humanizado ({@see CorridorLabel}). `from`/`to` se
 * devuelven SIEMPRE tal cual están en la tarifa. Lee `network_nodes` en solo lectura, sin importar clases
 * de network (acoplamiento al esquema de lectura, no al dominio ajeno).
 */
final readonly class DbCarrierCorridorReader implements CarrierCorridorReader
{
    private const CHUNK = 1000;

    public function corridors(?string $carrierId, ?int $limit = null, ?int $offset = null): array
    {
        $query = $this->filtered($carrierId)
            ->select(['corridor_from', 'corridor_to', 'corridor_type'])
            ->groupBy(['corridor_from', 'corridor_to', 'corridor_type'])
            ->orderBy('corridor_type')
            ->orderBy('corridor_from')
            ->orderBy('corridor_to');

        if ($offset !== null) {
            $query->offset(max(0, $offset));
        }
        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return [];
        }

        /** @var list<array{from: string, to: string, type: string}> $corridors */
        $corridors = [];
        foreach ($rows as $row) {
            $corridors[] = [
                'from' => self::str($row->corridor_from),
                'to' => self::str($row->corridor_to),
                'type' => self::str($row->corridor_type),
            ];
        }

        $classes = $this->vehicleClasses($carrierId, $corridors);
        $labels = $this->nodeNames(array_merge(
            array_column($corridors, 'from'),
            array_column($corridors, 'to'),
        ));

        $views = [];
        foreach ($corridors as $corridor) {
            $key = self::key($corridor['from'], $corridor['to'], $corridor['type']);
            $views[] = new CarrierCorridorView(
                $corridor['from'],
                $corridor['to'],
                $this->label($corridor['from'], $labels),
                $this->label($corridor['to'], $labels),
                $corridor['type'],
                $classes[$key] ?? [],
            );
        }

        return $views;
    }

    public function count(?string $carrierId): int
    {
        $distinct = $this->filtered($carrierId)
            ->select(['corridor_from', 'corridor_to', 'corridor_type'])
            ->groupBy(['corridor_from', 'corridor_to', 'corridor_type']);

        return DB::query()->fromSub($distinct, 'corridors')->count();
    }

    /**
     * Tarifas del transportista (o de todos si es null), sin orden ni recorte.
     */
    private function filtered(?string $carrierId): Builder
    {
        $query = DB::table('carrier_tariffs');

        if ($carrierId !== null && $carrierId !== '') {
            $query->where('carrier_id', $carrierId);
        }

        return $query;
    }

    /**
     * Clases de vehículo distintas (orden alfabético) por corredor de la página, en lote.
     *
     * @param  list<array{from: string, to: string, type: string}> $corridors
     * @return array<string, list<string>>                         clave del corredor → clases
     */
    private function vehicleClasses(?string $carrierId, array $corridors): array
    {
        $froms = array_values(array_unique(array_column($corridors, 'from')));
        $tos = array_values(array_unique(array_column($corridors, 'to')));

        $wanted = [];
        foreach ($corridors as $corridor) {
            $wanted[self::key($corridor['from'], $corridor['to'], $corridor['type'])] = true;
        }

        // Se acota por orígenes y destinos de la página; las combinaciones cruzadas que no son de la página
        // se descartan en PHP por la clave del corredor.
        $rows = $this->filtered($carrierId)
            ->whereIn('corridor_from', $froms)
            ->whereIn('corridor_to', $tos)
            ->distinct()
            ->orderBy('vehicle_class')
            ->get(['corridor_from', 'corridor_to', 'corridor_type', 'vehicle_class']);

        $out = [];
        foreach ($rows as $row) {
            $key = self::key(self::str($row->corridor_from), self::str($row->corridor_to), self::str($row->corridor_type));
            if (!isset($wanted[$key])) {
                continue;
            }
            $class = self::str($row->vehicle_class);
            if ($class !== '' && !in_array($class, $out[$key] ?? [], true)) {
                $out[$key][] = $class;
            }
        }

        return $out;
    }

    /**
     * Nombre de nodo por slug: por código (sin distinguir mayúsculas) y, si parece UUID, por id.
     *
     * @param  list<string>          $refs
     * @return array<string, string> ref en minúsculas → nombre del nodo
     */
    private function nodeNames(array $refs): array
    {
        $lowered = array_values(array_unique(array_filter(
            array_map(static fn (string $ref): string => mb_strtolower($ref), $refs),
            static fn (string $ref): bool => $ref !== '',
        )));
        if ($lowered === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk($lowered, self::CHUNK) as $chunk) {
            $rows = DB::table('network_nodes')
                ->whereIn(DB::raw('lower(code)'), $chunk)
                ->get(['code', 'name']);
            foreach ($rows as $row) {
                $out[mb_strtolower(self::str($row->code))] = self::str($row->name);
            }
        }

        // Por id solo los que parecen UUID (comparar texto arbitrario contra una columna uuid falla en pgsql).
        $uuids = array_values(array_filter($lowered, static fn (string $ref): bool => self::isUuid($ref)));
        foreach (array_chunk($uuids, self::CHUNK) as $chunk) {
            foreach (DB::table('network_nodes')->whereIn('id', $chunk)->get(['id', 'name']) as $row) {
                $out[mb_strtolower(self::str($row->id))] = self::str($row->name);
            }
        }

        return $out;
    }

    /**
     * Etiqueta legible: nombre del nodo si hay mapeo; si no, el slug humanizado.
     *
     * @param array<string, string> $names
     */
    private function label(string $ref, array $names): string
    {
        $name = $names[mb_strtolower($ref)] ?? '';

        return $name !== '' ? $name : CorridorLabel::humanize($ref);
    }

    private static function key(string $from, string $to, string $type): string
    {
        return $from."\x1F".$to."\x1F".$type;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
