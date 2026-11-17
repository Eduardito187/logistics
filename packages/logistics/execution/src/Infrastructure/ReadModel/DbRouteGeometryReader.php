<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\RouteGeometryReader;
use Throwable;

/**
 * Lector de la geometría de ruta. Garantiza que SIEMPRE haya una línea por CALLES si la ruta tiene al menos
 * dos puntos y el ruteador está disponible, en este orden:
 *
 * 1. La geometría persistida del plan (`planning_plans.route_geometry`) del mismo camión y día.
 * 2. Si el plan existe pero no tiene geometría, se le pide a Planificación que la calcule y persista (vía
 *    el callback `$ensurePlanGeometry`, que el provider cablea al servicio de aplicación de Planificación;
 *    Ejecución nunca escribe sobre Planning por sí misma) y se vuelve a leer.
 * 3. Si no hay plan con geometría, se GENERA la ruta por CALLES con OSRM a partir de las paradas de la ruta
 *    del chofer en orden de secuencia (callback `$streetGeometryForStops`). Así la ruta nunca sale en línea
 *    recta "por encima de todo" por el simple hecho de no tener un plan: se rutea por las calles reales.
 * 4. ÚLTIMO recurso (solo si el ruteador no está disponible): línea recta entre las paradas, con distancia
 *    haversine y duración estimada a velocidad media, para que la app y el admin siempre dibujen algo.
 *
 * Devuelve el esquema RouteGeometry (type/coordinates [[lng,lat],...]/distance_meters/duration_seconds).
 */
final readonly class DbRouteGeometryReader implements RouteGeometryReader
{
    /** Velocidad media (km/h) para estimar la duración de la línea recta de respaldo. */
    private const FALLBACK_SPEED_KMH = 30.0;

    /**
     * @param (Closure(string, string): bool)|null                              $ensurePlanGeometry     calcula
     *        y persiste la geometría del plan (vehículo, día); true si la guardó
     * @param (Closure(list<array{0: float, 1: float}>): (array<string, mixed>|null))|null $streetGeometryForStops
     *        rutea por calles (OSRM) los puntos [lng,lat] en orden y devuelve la geometría primitiva, o null
     */
    public function __construct(
        private ConnectionInterface $connection,
        private ?Closure $ensurePlanGeometry = null,
        private ?Closure $streetGeometryForStops = null,
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
                // Se cae a la generación por calles desde las paradas.
            }
        }

        // Sin plan con geometría: rutear por CALLES las paradas de la ruta (nunca recta por no tener plan).
        $points = $this->routeStopPoints($vehicleId, $routeDate);
        if (count($points) >= 2 && $this->streetGeometryForStops !== null) {
            try {
                $street = ($this->streetGeometryForStops)($points);
                if (is_array($street) && isset($street['coordinates']) && is_array($street['coordinates'])
                    && self::isDrawable($street['coordinates'])) {
                    /** @var array<string, mixed> $street */
                    return $street;
                }
            } catch (Throwable) {
                // Se cae a la recta (solo si el ruteador no está disponible).
            }
        }

        return $this->straightLineFromPoints($points);
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
            || !self::isDrawable($decoded['coordinates'])) {
            // No dibujable: sin dos puntos DISTINTOS (p. ej. una geometría degenerada que quedó persistida con
            // todas sus coordenadas iguales y distancia 0) la devolvemos como ausente, para caer al recálculo
            // del plan y, en último caso, a la línea recta entre las paradas reales de la ruta (que sí dibuja).
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
     * Coordenadas [lng,lat] de las paradas de la ruta del chofer, en orden de secuencia (coordenada de la
     * parada o, si falta, la del envío). Lista vacía si no se pudo leer.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function routeStopPoints(string $vehicleId, string $routeDate): array
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
            return [];
        }

        $points = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $lat = $data['stop_lat'] ?? $data['shipment_lat'] ?? null;
            $lng = $data['stop_lng'] ?? $data['shipment_lng'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng)) {
                continue;
            }
            $points[] = [(float) $lng, (float) $lat];
        }

        return $points;
    }

    /**
     * Línea recta entre los puntos [lng,lat] dados (último recurso cuando el ruteador no está disponible).
     * Null si no hay al menos dos puntos DISTINTOS.
     *
     * @param  list<array{0: float, 1: float}> $points
     * @return array<string, mixed>|null
     */
    private function straightLineFromPoints(array $points): ?array
    {
        if (!self::isDrawable($points)) {
            return null;
        }

        $distance = 0.0;
        $previous = null;
        foreach ($points as $point) {
            if ($previous !== null) {
                $distance += self::haversineMeters($previous, $point);
            }
            $previous = $point;
        }

        return [
            'type' => 'LineString',
            'coordinates' => $points,
            'distance_meters' => round($distance, 1),
            'duration_seconds' => round($distance / (self::FALLBACK_SPEED_KMH * 1000.0 / 3600.0), 1),
        ];
    }

    /**
     * ¿La lista de coordenadas dibuja una línea de verdad? Exige al menos DOS puntos DISTINTOS: una geometría
     * con dos o más coordenadas IGUALES (longitud/latitud repetidas) colapsa a un punto y no debe servirse.
     *
     * @param array<int|string, mixed> $coordinates lista de pares [lng, lat]
     */
    private static function isDrawable(array $coordinates): bool
    {
        $first = null;
        foreach ($coordinates as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                continue;
            }
            $point = [(float) $pair[0], (float) $pair[1]];
            if ($first === null) {
                $first = $point;

                continue;
            }
            if ($point !== $first) {
                return true;
            }
        }

        return false;
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
