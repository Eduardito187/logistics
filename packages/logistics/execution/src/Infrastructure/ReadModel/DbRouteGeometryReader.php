<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\RouteGeometryReader;
use Throwable;

/**
 * Lector de la geometría de ruta. Garantiza que SIEMPRE haya línea planificada si la ruta tiene al menos
 * dos puntos, en este orden:
 *
 * 1. La geometría persistida del plan (`planning_plans.route_geometry`) del mismo camión y día.
 * 2. Si el plan existe pero no tiene geometría, se le pide a Planificación que la calcule y persista (vía
 *    el callback `$ensurePlanGeometry`, que el provider cablea al servicio de aplicación de Planificación;
 *    Ejecución nunca escribe sobre Planning por sí misma) y se vuelve a leer.
 * 3. Si no hay plan o no se pudo calcular: línea RECTA entre las paradas de la ruta del chofer en orden de
 *    secuencia (coordenada de la parada o, si falta, la del envío), con distancia haversine y duración
 *    estimada a velocidad media. Así la app y el admin siempre dibujan algo.
 *
 * Devuelve el esquema RouteGeometry (type/coordinates [[lng,lat],...]/distance_meters/duration_seconds).
 */
final readonly class DbRouteGeometryReader implements RouteGeometryReader
{
    /** Velocidad media (km/h) para estimar la duración de la línea recta de respaldo. */
    private const FALLBACK_SPEED_KMH = 30.0;

    /**
     * @param (Closure(string, string): bool)|null $ensurePlanGeometry calcula y persiste la geometría del
     *                                                                 plan (vehículo, día); true si la guardó
     */
    public function __construct(
        private ConnectionInterface $connection,
        private ?Closure $ensurePlanGeometry = null,
    ) {}

    public function forVehicleAndDate(string $vehicleId, string $routeDate): ?array
    {
        if (trim($vehicleId) === '' || trim($routeDate) === '') {
            return null;
        }

        $persisted = $this->persistedPlanGeometry($vehicleId, $routeDate);
        if ($persisted !== null) {
            return $persisted;
        }

        if ($this->ensurePlanGeometry !== null && $this->planExists($vehicleId, $routeDate)) {
            try {
                if (($this->ensurePlanGeometry)($vehicleId, $routeDate)) {
                    $persisted = $this->persistedPlanGeometry($vehicleId, $routeDate);
                    if ($persisted !== null) {
                        return $persisted;
                    }
                }
            } catch (Throwable) {
                // Se cae a la línea recta.
            }
        }

        return $this->straightLineFromRouteStops($vehicleId, $routeDate);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function persistedPlanGeometry(string $vehicleId, string $routeDate): ?array
    {
        try {
            $raw = $this->connection->table('planning_plans')
                ->where('vehicle_id', $vehicleId)
                ->where('plan_date', $routeDate)
                ->value('route_geometry');
        } catch (Throwable) {
            return null;
        }

        // El driver de Postgres puede devolver el jsonb ya decodificado (array) o como string JSON crudo.
        // Un `route_geometry` NULL cae como string→else = null y lo descarta la validación de abajo.
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (!is_array($decoded) || !isset($decoded['coordinates']) || !is_array($decoded['coordinates'])
            || count($decoded['coordinates']) < 2) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function planExists(string $vehicleId, string $routeDate): bool
    {
        try {
            return $this->connection->table('planning_plans')
                ->where('vehicle_id', $vehicleId)
                ->where('plan_date', $routeDate)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Línea recta entre las paradas de la ruta del chofer, en orden de secuencia. Null si no hay al menos
     * dos puntos con coordenada.
     *
     * @return array<string, mixed>|null
     */
    private function straightLineFromRouteStops(string $vehicleId, string $routeDate): ?array
    {
        try {
            $rows = $this->connection->table('execution_driver_route_stops as s')
                ->join('execution_driver_routes as r', 'r.id', '=', 's.route_id')
                ->leftJoin('shipments as sh', 'sh.id', '=', 's.shipment_id')
                ->where('r.vehicle_id', $vehicleId)
                ->where('r.route_date', $routeDate)
                ->orderBy('s.sequence')
                ->get(['s.lat as stop_lat', 's.lng as stop_lng', 'sh.lat as shipment_lat', 'sh.lng as shipment_lng']);
        } catch (Throwable) {
            return null;
        }

        $coordinates = [];
        $distance = 0.0;
        $previous = null;
        foreach ($rows as $row) {
            $data = (array) $row;
            $lat = $data['stop_lat'] ?? $data['shipment_lat'] ?? null;
            $lng = $data['stop_lng'] ?? $data['shipment_lng'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng)) {
                continue;
            }

            $point = [(float) $lng, (float) $lat];
            if ($previous !== null) {
                $distance += self::haversineMeters($previous, $point);
            }
            $coordinates[] = $point;
            $previous = $point;
        }

        if (count($coordinates) < 2) {
            return null;
        }

        return [
            'type' => 'LineString',
            'coordinates' => $coordinates,
            'distance_meters' => round($distance, 1),
            'duration_seconds' => round($distance / (self::FALLBACK_SPEED_KMH * 1000.0 / 3600.0), 1),
        ];
    }

    /**
     * @param array{0: float, 1: float} $from [lng, lat]
     * @param array{0: float, 1: float} $to   [lng, lat]
     */
    private static function haversineMeters(array $from, array $to): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($to[1] - $from[1]);
        $dLng = deg2rad($to[0] - $from[0]);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($from[1])) * cos(deg2rad($to[1])) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }
}
