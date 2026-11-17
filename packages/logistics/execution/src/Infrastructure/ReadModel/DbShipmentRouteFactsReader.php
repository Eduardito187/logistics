<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\ShipmentRouteFactsReader;
use Logistics\Execution\Application\Service\StaleRouteStops;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Throwable;

/**
 * Lee por el query builder, en solo lectura, el día de cierre de la entrega (`execution_deliveries`) y las
 * coordenadas del destino (`shipments`). No usa modelos de otros contextos.
 *
 * El día de cierre sale de la prueba de entrega; si no la hay (cierre con faltantes), de la hora de ese
 * cierre; y como último recurso de la última modificación de la fila. Siempre en la zona de la operación,
 * porque la fecha de la ruta es un día calendario local.
 */
final readonly class DbShipmentRouteFactsReader implements ShipmentRouteFactsReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function closedDays(array $shipmentIds): array
    {
        $ids = self::clean($shipmentIds);
        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->connection->table('execution_deliveries')
                ->whereIn('shipment_id', $ids)
                ->where('status', DeliveryStatus::Delivered->value)
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }

        $days = [];
        foreach ($rows as $row) {
            $day = StaleRouteStops::dayOf($row->pod_captured_at ?? null)
                ?? StaleRouteStops::dayOf($row->shortage_closed_at ?? null)
                ?? StaleRouteStops::dayOf($row->updated_at ?? null);
            if ($day !== null) {
                $days[(string) $row->shipment_id] = $day;
            }
        }

        return $days;
    }

    public function coordinates(array $shipmentIds): array
    {
        $ids = self::clean($shipmentIds);
        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->connection->table('shipments')
                ->whereIn('id', $ids)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->get(['id', 'lat', 'lng'])
                ->all();
        } catch (Throwable) {
            return [];
        }

        $points = [];
        foreach ($rows as $row) {
            if (is_numeric($row->lat) && is_numeric($row->lng)) {
                $points[(string) $row->id] = ['lat' => (float) $row->lat, 'lng' => (float) $row->lng];
            }
        }

        return $points;
    }

    /**
     * @param  list<string> $shipmentIds
     * @return list<string>
     */
    private static function clean(array $shipmentIds): array
    {
        return array_values(array_unique(array_filter(
            $shipmentIds,
            static fn (string $id): bool => trim($id) !== '',
        )));
    }
}
