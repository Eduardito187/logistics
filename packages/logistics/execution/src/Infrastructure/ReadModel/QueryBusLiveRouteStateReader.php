<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Logistics\Execution\Application\Contract\LiveRouteStateReader;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Adaptador del estado en vivo de la ruta contra el MOTOR ÚNICO de ETA de telemetría, por el QueryBus
 * compartido. No importa el contexto telemetría en tiempo de compilación: resuelve su consulta por nombre
 * (`class_exists`), igual que el lector de geometría resuelve el servicio de planificación. Si el contexto
 * no está cargado (docker nuevo, telemetría apagada) devuelve null y Ejecución usa su ETA planificada.
 *
 * Es solo lectura y nunca lanza: cualquier error del motor degrada a null (sin ETA viva ni "fuera de ruta").
 */
final readonly class QueryBusLiveRouteStateReader implements LiveRouteStateReader
{
    /** Consulta del motor de ETA de telemetría (resuelta por nombre para no acoplar los packages). */
    private const ETA_QUERY = 'Logistics\\Telemetry\\Application\\Query\\GetVehicleEtas\\GetVehicleEtas';

    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function forVehicle(string $vehicleId): ?array
    {
        try {
            $view = $this->askEngine(trim($vehicleId));
        } catch (Throwable) {
            // Cualquier error del motor de ETA degrada a null: sin ETA viva ni "fuera de ruta".
            $view = null;
        }

        return $this->normalizeView($view);
    }

    /**
     * Despacha la consulta del motor de ETA por nombre (sin acoplar telemetría) y devuelve su vista cruda.
     */
    private function askEngine(string $vehicleId): mixed
    {
        $class = self::ETA_QUERY;
        if ($vehicleId === '' || !class_exists($class)) {
            return null;
        }

        // La existencia del motor ya la garantiza `class_exists` (telemetría puede no estar presente en un
        // despliegue reducido). La consulta implementa Query; se despacha por el bus compartido.
        return $this->queryBus->ask(new $class($vehicleId));
    }

    /**
     * @return array{off_route: bool, off_route_since: string|null, position: array{lat: float, lng: float, recorded_at: string}|null, position_stale: bool, etas: array<string, string>, reroute_geometry: list<array{lat: float, lng: float}>|null}|null
     */
    private function normalizeView(mixed $view): ?array
    {
        if (!is_object($view) || !method_exists($view, 'toArray')) {
            return null;
        }

        $data = $view->toArray();

        return is_array($data) ? $this->normalize($data) : null;
    }

    /**
     * Traduce la vista primitiva del motor de ETA al shape del puerto: mapa envío→ETA viva y estado de
     * desvío. Solo entra la ETA viva (`eta`), no la planificada.
     *
     * @param  array<string, mixed>                                                                                                                                                                                                                   $data
     * @return array{off_route: bool, off_route_since: string|null, position: array{lat: float, lng: float, recorded_at: string}|null, position_stale: bool, etas: array<string, string>, reroute_geometry: list<array{lat: float, lng: float}>|null}
     */
    private function normalize(array $data): array
    {
        $etas = [];
        $stops = $data['stops'] ?? [];
        if (is_array($stops)) {
            foreach ($stops as $stop) {
                if (!is_array($stop)) {
                    continue;
                }
                $shipmentId = $stop['shipment_id'] ?? null;
                $eta = $stop['eta'] ?? null;
                if (is_string($shipmentId) && $shipmentId !== '' && is_string($eta) && $eta !== '') {
                    $etas[$shipmentId] = $eta;
                }
            }
        }

        $position = $data['position'] ?? null;
        $since = $data['off_route_since'] ?? null;

        return [
            'off_route' => (bool) ($data['off_route'] ?? false),
            'off_route_since' => is_string($since) ? $since : null,
            'position' => $this->position(is_array($position) ? $position : null),
            'position_stale' => (bool) ($data['position_stale'] ?? false),
            'etas' => $etas,
            'reroute_geometry' => $this->geometry($data['reroute_geometry'] ?? null),
        ];
    }

    /**
     * Normaliza la geometría del reruteo a una lista de puntos `{lat, lng}` numéricos, o null. Un valor ausente,
     * incompleto o mal formado degrada a null: nunca se devuelve una polilínea a medias.
     *
     * @return list<array{lat: float, lng: float}>|null
     */
    private function geometry(mixed $geometry): ?array
    {
        if (!is_array($geometry) || count($geometry) < 2) {
            return null;
        }

        $points = [];
        foreach ($geometry as $point) {
            if (!is_array($point) || !is_numeric($point['lat'] ?? null) || !is_numeric($point['lng'] ?? null)) {
                return null;
            }
            $points[] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
        }

        return $points;
    }

    /**
     * @param  array<string, mixed>|null                               $position
     * @return array{lat: float, lng: float, recorded_at: string}|null
     */
    private function position(?array $position): ?array
    {
        if ($position === null) {
            return null;
        }

        $lat = $position['lat'] ?? null;
        $lng = $position['lng'] ?? null;
        $recordedAt = $position['recorded_at'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lng) || !is_string($recordedAt)) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'recorded_at' => $recordedAt,
        ];
    }
}
