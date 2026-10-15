<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Logistics\Execution\Domain\ValueObject\ScanType;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\ScanEventRecord;

/**
 * Lógica compartida para alimentar el mapa reutilizable (RouteMap.vue) en el SPA, portada del
 * VehicleRouteResource de Filament (la referencia de qué debe verse en el mapa):
 *
 *  - osrmHost(): el host de OSRM configurado por el proyecto, para que el trazado planificado siga
 *    calles reales igual que en el admin (nunca el público por defecto del componente).
 *  - plannedGeometryForVehicleDay(): la geometría planificada ya PERSISTIDA por Planning
 *    (planning_plans.route_geometry), emparejando por clave natural vehículo + día. Solo lectura.
 *  - actualTrackForShipments(): el recorrido REAL del camión con los puntos GPS de TRÁNSITO
 *    (escaneos IN_TRANSIT) de las entregas de esos envíos, en orden cronológico.
 *
 * Degrada con gracia: ante tablas/columnas ausentes o datos inusables devuelve [] y el mapa cae al
 * OSRM del navegador y, en última instancia, a la línea recta entre paradas.
 */
trait ResolvesRouteMapData
{
    /**
     * Host de OSRM del proyecto (sin la barra final), para trazar la ruta planificada siguiendo calles.
     */
    private function osrmHost(): string
    {
        $host = config('logistics-planning.osrm_host', 'https://router.project-osrm.org');
        $host = is_string($host) && $host !== '' ? $host : 'https://router.project-osrm.org';

        return rtrim($host, '/');
    }

    /**
     * Geometría planificada persistida por Planning para el plan del vehículo en esa fecha
     * (planning_plans.route_geometry). La ruta de Ejecución y el plan comparten clave natural
     * (vehículo + día). Devuelve [] si la tabla/columna no existe o la geometría no es usable.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function plannedGeometryForVehicleDay(string $vehicleId, string $routeDate): array
    {
        if (!DbSchema::hasTable('planning_plans') || !DbSchema::hasColumn('planning_plans', 'route_geometry')) {
            return [];
        }

        /** @var mixed $raw */
        $raw = DB::table('planning_plans')
            ->where('vehicle_id', $vehicleId)
            ->where('plan_date', $routeDate)
            ->value('route_geometry');

        return $this->normalizeGeometry($raw);
    }

    /**
     * Normaliza la geometría persistida a una lista de pares [lat, lng] lista para Leaflet. Acepta un
     * objeto GeoJSON LineString ({"type":"LineString","coordinates":[[lng,lat],...]}) o directamente la
     * lista de coordenadas [[lng,lat],...] (orden GeoJSON: longitud primero). Exige al menos dos puntos.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function normalizeGeometry(mixed $raw): array
    {
        if (is_string($raw)) {
            /** @var mixed $raw */
            $raw = json_decode($raw, true);
        }

        if (is_array($raw) && isset($raw['coordinates']) && is_array($raw['coordinates'])) {
            $raw = $raw['coordinates'];
        }

        if (!is_array($raw)) {
            return [];
        }

        $line = [];
        foreach ($raw as $pair) {
            if (!is_array($pair) || !array_key_exists(0, $pair) || !array_key_exists(1, $pair)) {
                return [];
            }

            $lng = $this->mapCoord($pair[0]);
            $lat = $this->mapCoord($pair[1]);

            if ($lat === null || $lng === null) {
                return [];
            }

            // Persistido como [lng, lat] (GeoJSON); Leaflet dibuja con [lat, lng].
            $line[] = [$lat, $lng];
        }

        return count($line) >= 2 ? $line : [];
    }

    /**
     * Recorrido real del camión: SOLO los puntos GPS de TRÁNSITO (escaneos IN_TRANSIT) de las entregas
     * del vehículo para esos envíos, ordenados por momento de captura. Se excluyen a propósito los
     * escaneos de preparación, carga, descarga y prueba de entrega: ocurren parados en el CD o en el
     * punto del cliente y, mezclados, dibujaban una línea roja sin sentido. Omite puntos sin coordenadas.
     *
     * @param  list<string>                    $shipmentIds
     * @return list<array{0: float, 1: float}>
     */
    private function actualTrackForShipments(string $vehicleId, array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        /** @var list<string> $deliveryIds */
        $deliveryIds = DeliveryRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->whereIn('shipment_id', $shipmentIds)
            ->pluck('id')
            ->all();

        if ($deliveryIds === []) {
            return [];
        }

        $track = [];
        $events = ScanEventRecord::query()
            ->whereIn('delivery_id', $deliveryIds)
            ->where('type', ScanType::InTransit->value)
            ->orderBy('occurred_at')
            ->get(['lat', 'lng', 'occurred_at']);

        foreach ($events as $event) {
            $lat = $this->mapCoord($event->lat);
            $lng = $this->mapCoord($event->lng);

            if ($lat === null || $lng === null) {
                continue;
            }

            $track[] = [$lat, $lng];
        }

        return $track;
    }

    /**
     * Normaliza una coordenada a float, o null si no es numérica. Uso interno del trait.
     */
    private function mapCoord(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
