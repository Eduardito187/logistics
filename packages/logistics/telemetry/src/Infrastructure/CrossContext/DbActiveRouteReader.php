<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\CrossContext;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Telemetry\Application\Contract\ActiveRouteReader;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use stdClass;
use Throwable;

/**
 * Ruta del día de un vehículo leída por query builder (solo lectura, sin modelos de otros contextos):
 *
 *  - geometría: `planning_plans.route_geometry` (GeoJSON LineString `[[lng, lat], …]`) del plan PUBLISHED del
 *    vehículo para la fecha (un plan por vehículo y día);
 *  - paradas restantes: `execution_driver_route_stops` PENDING de la ruta del día, con coordenadas de la
 *    parada o, si faltan, las del envío (`shipments.lat/lng`);
 *  - universo del recálculo: rutas del día PLANNED / IN_PROGRESS.
 *
 * Tolerante (SafeRead): sin tablas, sin plan o con datos mal formados devuelve listas vacías.
 */
final readonly class DbActiveRouteReader implements ActiveRouteReader
{
    /** Estados de ruta abierta (todavía hay paradas que recorrer). */
    private const array OPEN_ROUTES = ['PLANNED', 'IN_PROGRESS'];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function plannedGeometry(string $vehicleId, DateTimeImmutable $day): array
    {
        if (!SafeRead::isUuid($vehicleId)) {
            return [];
        }

        $raw = SafeRead::run($this->connection, fn (): mixed => $this->connection->table('planning_plans')
            ->where('vehicle_id', $vehicleId)
            ->where('plan_date', $day->format('Y-m-d'))
            ->where('status', 'PUBLISHED')
            ->value('route_geometry'), null);

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || !is_array($raw['coordinates'] ?? null)) {
            return [];
        }

        $points = [];
        foreach ($raw['coordinates'] as $pair) {
            if (!is_array($pair) || !is_numeric($pair[0] ?? null) || !is_numeric($pair[1] ?? null)) {
                continue;
            }

            try {
                // GeoJSON: [lng, lat].
                $points[] = GeoPoint::of((float) $pair[1], (float) $pair[0]);
            } catch (Throwable) {
                continue;
            }
        }

        return $points;
    }

    public function remainingStops(string $vehicleId, DateTimeImmutable $day): array
    {
        if (!SafeRead::isUuid($vehicleId)) {
            return [];
        }

        /** @var list<stdClass> $rows */
        $rows = SafeRead::run($this->connection, fn (): array => $this->connection->table('execution_driver_route_stops as s')
            ->join('execution_driver_routes as r', 'r.id', '=', 's.route_id')
            ->leftJoin('shipments as sh', 'sh.id', '=', 's.shipment_id')
            ->where('r.vehicle_id', $vehicleId)
            ->where('r.route_date', $day->format('Y-m-d'))
            ->where('s.status', 'PENDING')
            ->orderBy('s.sequence')
            ->get([
                's.shipment_id',
                's.sequence',
                's.eta',
                's.order_reference',
                's.customer_name',
                's.lat as stop_lat',
                's.lng as stop_lng',
                'sh.lat as shipment_lat',
                'sh.lng as shipment_lng',
            ])
            ->all(), []);

        $stops = [];
        foreach ($rows as $row) {
            $lat = self::float($row->stop_lat ?? null) ?? self::float($row->shipment_lat ?? null);
            $lng = self::float($row->stop_lng ?? null) ?? self::float($row->shipment_lng ?? null);
            if ($lat === null || $lng === null) {
                $lat = null;
                $lng = null;
            }

            $stops[] = [
                'shipment_id' => (string) ($row->shipment_id ?? ''),
                'sequence' => (int) ($row->sequence ?? 0),
                'lat' => $lat,
                'lng' => $lng,
                'planned_eta' => self::str($row->eta ?? null),
                'order_reference' => self::str($row->order_reference ?? null),
                'customer_name' => self::str($row->customer_name ?? null),
            ];
        }

        return $stops;
    }

    public function vehiclesWithOpenRoutes(DateTimeImmutable $day): array
    {
        /** @var list<mixed> $ids */
        $ids = SafeRead::run($this->connection, fn (): array => $this->connection->table('execution_driver_routes')
            ->where('route_date', $day->format('Y-m-d'))
            ->whereIn('status', self::OPEN_ROUTES)
            ->distinct()
            ->orderBy('vehicle_id')
            ->pluck('vehicle_id')
            ->all(), []);

        $result = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && (string) $id !== '') {
                $result[] = (string) $id;
            }
        }

        return $result;
    }

    private static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
