<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\ReadSide;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Tracking\Application\Contract\RouteStopsReader;

/**
 * Lee las paradas de la ruta del chofer desde `execution_driver_route_stops` por el query builder, en solo
 * lectura y sin acoplar el seguimiento al dominio de execution (mismo patrón que
 * {@see DbShipmentOrderReferenceReader}).
 */
final readonly class DbRouteStopsReader implements RouteStopsReader
{
    /** Estado de una parada que todavía no se resolvió. */
    private const PENDING = 'PENDING';

    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function stopsOfRoute(string $routeId): array
    {
        $id = trim($routeId);
        if ($id === '') {
            return [];
        }

        $rows = $this->connections->connection()
            ->table('execution_driver_route_stops')
            ->where('route_id', $id)
            ->orderBy('sequence')
            ->get(['shipment_id', 'sequence', 'status']);

        $stops = [];
        foreach ($rows as $row) {
            if (!is_string($row->shipment_id ?? null) || $row->shipment_id === '') {
                continue;
            }

            $stops[] = [
                'shipment_id' => $row->shipment_id,
                'sequence' => (int) ($row->sequence ?? 0),
                'pending' => strtoupper((string) ($row->status ?? '')) === self::PENDING,
            ];
        }

        return $stops;
    }

    public function routesWithPendingStops(): array
    {
        $ids = $this->connections->connection()
            ->table('execution_driver_route_stops')
            ->where('status', self::PENDING)
            ->distinct()
            ->pluck('route_id');

        $out = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $out[] = $id;
            }
        }

        return $out;
    }

    public function shipmentsWithoutTracking(int $limit): array
    {
        $ids = $this->connections->connection()
            ->table('shipments as s')
            ->leftJoin('tracking_shipments as t', 't.shipment_id', '=', 's.id')
            ->whereNull('t.id')
            ->where('s.status', '<>', 'CANCELLED')
            ->orderBy('s.id')
            ->limit(max(1, $limit))
            ->pluck('s.id');

        $out = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $out[] = $id;
            }
        }

        return $out;
    }
}
