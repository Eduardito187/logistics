<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Execution\Application\ReadModel\RouteStopView;
use Logistics\Fleet\Application\Query\GetMyCrew\GetMyCrew;
use Logistics\Fleet\Application\ReadModel\MyCrewView;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Home del CHOFER (Fase 4 — homes por rol): "mi día". Para el chofer logueado (vinculado por
 * users.driver_id al Fleet), arma su tripulación de hoy (vehículo + ayudantes), su ruta vigente
 * (execution) con el progreso de paradas, y la próxima parada. Prefiere los QueryHandlers del QueryBus
 * (CQRS) y cae a lectura directa solo para el nombre del chofer. Tolerante: cada fuente en su try/catch.
 * SOLO lectura; sin UUID visible (referencias: nombre, placa, nº de pedido).
 */
final class DriverHome
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * El día del chofer, en primitivos y tolerante.
     *
     * @return array{
     *   driver_name: string,
     *   vehicle_plate: string|null,
     *   helpers: list<string>,
     *   has_crew: bool,
     *   route: array{status: string, total: int, completed: int, remaining: int, next: array{label: string, eta: string|null}|null, stops: list<array{label: string, eta: string|null, state: string}>}|null
     * }
     */
    public function overview(string $driverId): array
    {
        $crew = $this->crewToday($driverId);
        $vehicleId = $crew['vehicle_id'];

        return [
            'driver_name' => $crew['driver_name'] ?? $this->driverName($driverId) ?? 'Chofer',
            'vehicle_plate' => $crew['vehicle_plate'],
            'helpers' => $crew['helpers'],
            'has_crew' => $vehicleId !== null,
            'route' => $vehicleId !== null ? $this->route($vehicleId) : null,
        ];
    }

    /**
     * Tripulación de hoy del chofer.
     *
     * @return array{driver_name: string|null, vehicle_id: string|null, vehicle_plate: string|null, helpers: list<string>}
     */
    private function crewToday(string $driverId): array
    {
        $empty = ['driver_name' => null, 'vehicle_id' => null, 'vehicle_plate' => null, 'helpers' => []];

        try {
            $view = $this->queryBus->ask(new GetMyCrew(driverId: $driverId, date: now()->toDateString()));
            if (!$view instanceof MyCrewView) {
                return $empty;
            }

            $vehicleId = is_array($view->vehicle) && isset($view->vehicle['id']) && is_string($view->vehicle['id'])
                ? $view->vehicle['id']
                : null;
            $plate = is_array($view->vehicle) && isset($view->vehicle['plate']) && is_string($view->vehicle['plate'])
                ? $view->vehicle['plate']
                : null;
            $driverName = isset($view->driver['name']) && is_string($view->driver['name']) ? $view->driver['name'] : null;

            $helpers = [];
            foreach ($view->helpers as $helper) {
                if (is_array($helper) && isset($helper['name']) && is_string($helper['name']) && $helper['name'] !== '') {
                    $helpers[] = $helper['name'];
                }
            }

            return [
                'driver_name' => $driverName,
                'vehicle_id' => $vehicleId,
                'vehicle_plate' => $plate,
                'helpers' => $helpers,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * Ruta vigente del vehículo con el progreso y la próxima parada, o null si no hay ruta abierta.
     *
     * @return array{status: string, total: int, completed: int, remaining: int, next: array{label: string, eta: string|null}|null, stops: list<array{label: string, eta: string|null, state: string}>}|null
     */
    private function route(string $vehicleId): ?array
    {
        try {
            $view = $this->queryBus->ask(new GetDriverRoute($vehicleId));
            if (!$view instanceof DriverRouteView) {
                return null;
            }

            $next = null;
            if ($view->currentStop instanceof RouteStopView) {
                $next = [
                    'label' => $this->stopLabel($view->currentStop),
                    'eta' => $this->isoToShort($view->currentStop->etaAt),
                ];
            }

            return [
                'status' => $view->status,
                'total' => $view->totalStops,
                'completed' => $view->completedStops,
                'remaining' => $view->stopsRemaining,
                'next' => $next,
                'stops' => $this->mapStops($view->stops),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<RouteStopView>                                         $stops
     * @return list<array{label: string, eta: string|null, state: string}>
     */
    private function mapStops(array $stops): array
    {
        $out = [];
        foreach ($stops as $stop) {
            $out[] = [
                'label' => $this->stopLabel($stop),
                'eta' => $this->isoToShort($stop->etaAt),
                'state' => $this->stopState($stop),
            ];
        }

        return $out;
    }

    private function stopLabel(RouteStopView $stop): string
    {
        $name = is_string($stop->customerName) && trim($stop->customerName) !== '' ? $stop->customerName : null;
        $ref = is_string($stop->orderReference) && trim($stop->orderReference) !== '' ? $stop->orderReference : null;

        return $name ?? $ref ?? ('Parada '.$stop->sequence);
    }

    private function stopState(RouteStopView $stop): string
    {
        if ($stop->settledAt !== null) {
            return strtoupper($stop->status) === 'FAILED' ? 'late' : 'done';
        }

        return match (strtoupper($stop->status)) {
            'IN_TRANSIT', 'CURRENT', 'ARRIVED' => 'warn',
            default => 'upcoming',
        };
    }

    /** Nombre del chofer (fleet_drivers.name), o null. Lectura tolerante. */
    private function driverName(string $driverId): ?string
    {
        try {
            $name = DB::table('fleet_drivers')->where('id', $driverId)->value('name');

            return is_string($name) && $name !== '' ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** ISO 8601 → "19 oct 09:00" legible, o null. */
    private function isoToShort(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->isoFormat('DD MMM HH:mm');
        } catch (Throwable) {
            return null;
        }
    }
}
