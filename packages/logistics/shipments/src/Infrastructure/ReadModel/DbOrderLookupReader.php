<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Logistics\Shipments\Application\Contract\OrderLookupReader;
use Logistics\Shipments\Application\ReadModel\OrderRouteFacts;
use Throwable;

/**
 * Implementación SQL de la consulta abierta de pedidos.
 *
 * Lectura cruzada de SOLO LECTURA (por el query builder, sin tocar los dominios ajenos) de:
 *  - shipments → código externo / referencia de orden (igualdad exacta sobre columnas indexadas);
 *  - tracking_shipments → estado visible, ruta, paradas pendientes por delante y hora estimada;
 *  - execution_driver_route_stops → parada real del envío (respaldo del seguimiento);
 *  - network_nodes → nombre legible del nodo.
 *
 * Es una consulta PUNTUAL: cada método corre UNA sentencia por fuente sobre un puñado de ids. Las fuentes
 * ajenas van aisladas en su try/catch: si una no está disponible, solo sus campos quedan sin dato.
 */
final readonly class DbOrderLookupReader implements OrderLookupReader
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Estado de una parada de ruta que todavía no se resolvió. */
    private const STOP_PENDING = 'PENDING';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function shipmentIdsByCode(string $code, int $limit): array
    {
        $code = trim($code);
        $limit = max(1, $limit);
        if ($code === '') {
            return [];
        }

        // Igualdad exacta sobre las columnas indexadas, probando el texto tal cual y en mayúsculas/minúsculas
        // (los códigos se guardan como llegan de cada fuente). El id solo se compara si el texto tiene forma
        // de UUID: comparar otra cosa contra una columna uuid falla en PostgreSQL (22P02).
        $variants = array_values(array_unique([$code, mb_strtoupper($code), mb_strtolower($code)]));
        $isUuid = self::isUuid($code);

        $rows = $this->connection->table('shipments')
            ->where(static function (Builder $where) use ($variants, $isUuid, $code): void {
                $where->whereIn('external_id', $variants)->orWhereIn('order_reference', $variants);
                if ($isUuid) {
                    $where->orWhere('id', strtolower($code));
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'order_reference']);

        $ids = [];
        $references = [];
        foreach ($rows as $row) {
            $id = self::str($row->id ?? null);
            if ($id !== null) {
                $ids[$id] = true;
            }
            $reference = self::str($row->order_reference ?? null);
            if ($reference !== null) {
                $references[$reference] = true;
            }
        }

        // Un pedido con varios envíos (uno por destino) comparte la referencia de orden: se suman los hermanos.
        if ($references !== [] && count($ids) < $limit) {
            $siblings = $this->connection->table('shipments')
                ->whereIn('order_reference', array_map(strval(...), array_keys($references)))
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');
            foreach ($siblings as $sibling) {
                $id = self::str($sibling);
                if ($id !== null) {
                    $ids[$id] = true;
                }
            }
        }

        $list = array_map(strval(...), array_keys($ids));
        sort($list);

        return array_slice($list, 0, $limit);
    }

    public function routeFacts(array $shipmentIds): array
    {
        $ids = self::uuids($shipmentIds);
        if ($ids === []) {
            return [];
        }

        $tracking = $this->trackingRows($ids);
        $stops = $this->pendingStops($ids);

        $facts = [];
        foreach ($ids as $id) {
            $row = $tracking[$id] ?? null;
            $stop = $stops[$id] ?? null;

            $facts[$id] = new OrderRouteFacts(
                orderReference: $row['order_reference'] ?? null,
                trackingStatus: $row['status'] ?? null,
                trackingOnRoute: $row['on_route'] ?? false,
                trackingStopsAhead: $row['stops_ahead'] ?? null,
                trackingEta: $row['eta'] ?? null,
                liveOnRoute: $stop !== null,
                liveStopsAhead: $stop['stops_ahead'] ?? null,
                liveEta: $stop['eta'] ?? null,
            );
        }

        return $facts;
    }

    public function nodeNames(array $nodeIds): array
    {
        $ids = self::uuids($nodeIds);
        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->connection->table('network_nodes')->whereIn('id', $ids)->get(['id', 'name']);
        } catch (Throwable) {
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            $id = self::str($row->id ?? null);
            $name = self::str($row->name ?? null);
            if ($id !== null && $name !== null) {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * Referencia de orden y proyección de seguimiento de cada envío (una sentencia: envíos ⟕ seguimiento).
     *
     * @param  list<string>                                                                                                                     $ids
     * @return array<string, array{order_reference: string|null, status: string|null, on_route: bool, stops_ahead: int|null, eta: string|null}>
     */
    private function trackingRows(array $ids): array
    {
        try {
            $rows = $this->connection->table('shipments as s')
                ->leftJoin('tracking_shipments as t', 't.shipment_id', '=', 's.id')
                ->whereIn('s.id', $ids)
                ->get(['s.id', 's.order_reference', 's.external_id', 't.status', 't.route_id', 't.remaining_stops', 't.eta']);
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $id = self::str($row->id ?? null);
            if ($id === null) {
                continue;
            }

            $onRoute = self::str($row->route_id ?? null) !== null;

            $result[$id] = [
                'order_reference' => self::str($row->order_reference ?? null) ?? self::str($row->external_id ?? null),
                'status' => self::str($row->status ?? null),
                'on_route' => $onRoute,
                'stops_ahead' => $onRoute && is_numeric($row->remaining_stops ?? null) ? max(0, (int) $row->remaining_stops) : null,
                'eta' => self::iso($row->eta ?? null),
            ];
        }

        return $result;
    }

    /**
     * Parada PENDIENTE de cada envío en la ruta real del chofer, con las paradas pendientes que tiene por
     * delante en esa ruta (subconsulta correlacionada sobre el índice de la ruta). Si un envío tiene más de
     * una, queda la asignación más reciente.
     *
     * @param  list<string>                                             $ids
     * @return array<string, array{stops_ahead: int, eta: string|null}>
     */
    private function pendingStops(array $ids): array
    {
        try {
            $rows = $this->connection->table('execution_driver_route_stops as s')
                ->whereIn('s.shipment_id', $ids)
                ->where('s.status', self::STOP_PENDING)
                ->orderBy('s.created_at')
                ->selectRaw(
                    's.shipment_id, s.eta, '
                    .'(SELECT COUNT(*) FROM execution_driver_route_stops a '
                    .'WHERE a.route_id = s.route_id AND a.status = ? AND a.sequence < s.sequence) AS stops_ahead',
                    [self::STOP_PENDING],
                )
                ->get();
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $id = self::str($row->shipment_id ?? null);
            if ($id !== null) {
                $result[$id] = [
                    'stops_ahead' => is_numeric($row->stops_ahead ?? null) ? (int) $row->stops_ahead : 0,
                    'eta' => self::iso($row->eta ?? null),
                ];
            }
        }

        return $result;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(self::UUID_PATTERN, $value) === 1;
    }

    /**
     * Solo los valores con forma de UUID, sin repetidos (nunca se compara otra cosa contra una columna uuid).
     *
     * @param  list<string> $values
     * @return list<string>
     */
    private static function uuids(array $values): array
    {
        return array_values(array_unique(array_filter($values, self::isUuid(...))));
    }

    /** Normaliza a string no vacío o null. */
    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** Normaliza una marca de tiempo de la base a ISO 8601 (ATOM), o null si no es interpretable. */
    private static function iso(mixed $value): ?string
    {
        $raw = self::str($value);
        if ($raw === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($raw))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }
}
