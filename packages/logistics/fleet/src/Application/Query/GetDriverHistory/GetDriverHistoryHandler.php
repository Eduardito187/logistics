<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetDriverHistory;

use Illuminate\Database\Eloquent\Builder;
use Logistics\Execution\Domain\ValueObject\RouteStopStatus;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\RouteStopRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleIncidentRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleRouteRecord;
use Logistics\Fleet\Application\ReadModel\DriverHistoryView;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Arma el historial operativo del chofer leyendo el lado de lectura cross-context (monorepo, autoload
 * común): las tripulaciones del chofer en el rango (`fleet_crews`) se cruzan con las rutas de reparto
 * (`execution_driver_routes`) y sus paradas, y se cuentan los cierres por estado.
 *
 * El join prefiere `execution_driver_routes.driver_id` cuando está poblado; si no, cae al par
 * (vehicle_id, route_date == assembled_for). Los motivos de fallo salen de `execution_deliveries`
 * (failure_reason) y los de reprogramación de `execution_vehicle_incidents`. Todo en lote para evitar
 * N+1 (gotcha #22). Es una consulta pura de lectura: no escribe nada ni toca el dominio de Execution.
 *
 * @implements QueryHandler<GetDriverHistory, DriverHistoryView>
 */
final readonly class GetDriverHistoryHandler implements QueryHandler
{
    public function handle(Query $query): DriverHistoryView
    {
        assert($query instanceof GetDriverHistory);

        $driverId = $query->driverId;
        $from = $query->from;
        $to = $query->to;

        // Nombre legible del chofer (cae al id si ya no existe el registro).
        $driver = DriverModel::query()->find($driverId);
        $driverName = $driver instanceof DriverModel ? $driver->name : $driverId;

        // 1. Tripulaciones del chofer en el rango: una por camión y día (assembled_for).
        /** @var list<CrewModel> $crews */
        $crews = CrewModel::query()
            ->where('driver_id', $driverId)
            ->whereBetween('assembled_for', [$from, $to])
            ->orderBy('assembled_for')
            ->get()
            ->all();

        if ($crews === []) {
            return DriverHistoryView::empty($driverId, $driverName, $from, $to);
        }

        // 2. Placas de camiones y nombres de ayudantes, resueltos en lote.
        $vehicleIds = [];
        $helperIds = [];
        foreach ($crews as $crew) {
            $vehicleIds[] = $crew->vehicle_id;
            foreach ($crew->helper_ids as $helperId) {
                $helperIds[] = $helperId;
            }
        }
        $plates = $this->plates(array_values(array_unique($vehicleIds)));
        $helperNames = $this->helperNames(array_values(array_unique($helperIds)));

        // 3. Rutas candidatas del rango, indexadas por (camión, fecha): las confirmadas de este chofer
        //    (driver_id) tienen prioridad; las que no tienen chofer asignado son atribuibles por el camión
        //    de la tripulación; las que ya pertenecen a OTRO chofer se descartan.
        $routesByVehDate = [];
        $routes = VehicleRouteRecord::query()
            ->whereBetween('route_date', [$from, $to])
            ->where(function (Builder $builder) use ($driverId, $vehicleIds): void {
                /** @var Builder<VehicleRouteRecord> $builder */
                $builder->where('driver_id', $driverId)
                    ->orWhereIn('vehicle_id', array_values(array_unique($vehicleIds)));
            })
            ->get();
        foreach ($routes as $route) {
            $date = substr($route->route_date, 0, 10);
            $key = $route->vehicle_id.'|'.$date;
            if ($route->driver_id === $driverId) {
                $routesByVehDate[$key] = $route;
            } elseif ($route->driver_id === null && !isset($routesByVehDate[$key])) {
                $routesByVehDate[$key] = $route;
            }
        }

        // 4. Empareja cada tripulación con su ruta por (camión, fecha). Cada ruta se cuenta UNA sola vez
        //    aunque el chofer tenga dos tripulaciones el mismo día (evita el doble conteo de paradas).
        /** @var list<array{crew: CrewModel, route: VehicleRouteRecord|null, date: string}> $matched */
        $matched = [];
        $routeIds = [];
        $consumedRouteIds = [];
        foreach ($crews as $crew) {
            $date = substr($crew->assembled_for, 0, 10);
            $route = $routesByVehDate[$crew->vehicle_id.'|'.$date] ?? null;
            if ($route !== null && !isset($consumedRouteIds[$route->id])) {
                $consumedRouteIds[$route->id] = true;
                $routeIds[] = $route->id;
            } else {
                // Sin ruta propia ese día, o ruta ya contada por otra tripulación: fila de día en cero.
                $route = null;
            }
            $matched[] = ['crew' => $crew, 'route' => $route, 'date' => $date];
        }
        $routeIds = array_values(array_unique($routeIds));

        // 5. Paradas de esas rutas, agrupadas por ruta; motivos de fallo y de reprogramación en lote.
        $stopsByRoute = $this->stopsByRoute($routeIds);
        $failedShipmentIds = [];
        foreach ($stopsByRoute as $stops) {
            foreach ($stops as $stop) {
                if ($stop->status === RouteStopStatus::Failed->value) {
                    $failedShipmentIds[] = $stop->shipment_id;
                }
            }
        }
        $reasonByShipment = $this->failureReasonByShipment(array_values(array_unique($failedShipmentIds)));
        $rescheduleReasonsByRoute = $this->rescheduleReasonsByRoute($routeIds);

        // 6. Agregación: totales del rango + desglose por día.
        $plateSet = [];
        $dateSet = [];
        $totalStops = 0;
        $delivered = 0;
        $failed = 0;
        $rescheduled = 0;
        /** @var array<string, int> $failedByReason */
        $failedByReason = [];
        $days = [];

        foreach ($matched as $entry) {
            $crew = $entry['crew'];
            $route = $entry['route'];
            $date = $entry['date'];

            $plate = $plates[$crew->vehicle_id] ?? $crew->vehicle_id;
            $plateSet[$plate] = true;
            $dateSet[$date] = true;

            $helperNamesRow = [];
            foreach ($crew->helper_ids as $helperId) {
                $helperNamesRow[] = $helperNames[$helperId] ?? $helperId;
            }

            $dayDelivered = 0;
            $dayFailed = 0;
            $dayRescheduled = 0;

            if ($route !== null) {
                foreach ($stopsByRoute[$route->id] ?? [] as $stop) {
                    $totalStops++;
                    switch ($stop->status) {
                        case RouteStopStatus::Completed->value:
                            $dayDelivered++;
                            $delivered++;
                            break;
                        case RouteStopStatus::Failed->value:
                            $dayFailed++;
                            $failed++;
                            // Si la entrega no registró motivo, cae al cubo OTHER (best-effort).
                            $reason = $reasonByShipment[$stop->shipment_id] ?? 'OTHER';
                            $failedByReason[$reason] = ($failedByReason[$reason] ?? 0) + 1;
                            break;
                        case RouteStopStatus::Rescheduled->value:
                            $dayRescheduled++;
                            $rescheduled++;
                            break;
                    }
                }
            }

            $days[] = [
                'date' => $date,
                'vehicle_plate' => $plate,
                'helper_names' => $helperNamesRow,
                'delivered' => $dayDelivered,
                'failed' => $dayFailed,
                'rescheduled' => $dayRescheduled,
                'reschedule_reasons' => $route !== null
                    ? ($rescheduleReasonsByRoute[$route->id] ?? [])
                    : [],
            ];
        }

        $vehicles = array_keys($plateSet);
        sort($vehicles);

        return DriverHistoryView::of(
            $driverId,
            $driverName,
            $from,
            $to,
            count($dateSet),
            $vehicles,
            $totalStops,
            $delivered,
            $failed,
            $failedByReason,
            $rescheduled,
            $days,
        );
    }

    /**
     * Placas de camiones por id, en lote.
     *
     * @param  list<string>          $vehicleIds
     * @return array<string, string>
     */
    private function plates(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $plates = [];
        foreach (VehicleModel::query()->whereIn('id', $vehicleIds)->get(['id', 'plate']) as $vehicle) {
            $plates[$vehicle->id] = $vehicle->plate;
        }

        return $plates;
    }

    /**
     * Nombres de ayudantes por id, en lote.
     *
     * @param  list<string>          $helperIds
     * @return array<string, string>
     */
    private function helperNames(array $helperIds): array
    {
        if ($helperIds === []) {
            return [];
        }

        $names = [];
        foreach (HelperModel::query()->whereIn('id', $helperIds)->get(['id', 'name']) as $helper) {
            $names[$helper->id] = $helper->name;
        }

        return $names;
    }

    /**
     * Paradas agrupadas por id de ruta, en lote.
     *
     * @param  list<string>                         $routeIds
     * @return array<string, list<RouteStopRecord>>
     */
    private function stopsByRoute(array $routeIds): array
    {
        if ($routeIds === []) {
            return [];
        }

        $byRoute = [];
        foreach (RouteStopRecord::query()->whereIn('route_id', $routeIds)->get() as $stop) {
            $byRoute[$stop->route_id][] = $stop;
        }

        return $byRoute;
    }

    /**
     * Motivo de fallo (FailureReason) por envío, en lote, para las paradas fallidas.
     *
     * @param  list<string>          $shipmentIds
     * @return array<string, string>
     */
    private function failureReasonByShipment(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        $reasons = [];
        foreach (DeliveryRecord::query()->whereIn('shipment_id', $shipmentIds)->get(['shipment_id', 'failure_reason']) as $delivery) {
            if ($delivery->failure_reason !== null) {
                $reasons[$delivery->shipment_id] = $delivery->failure_reason;
            }
        }

        return $reasons;
    }

    /**
     * Motivos (tipos de incidente) de reprogramación por ruta, en lote. Solo incidentes que fijaron una
     * fecha de reprogramación.
     *
     * @param  list<string>                $routeIds
     * @return array<string, list<string>>
     */
    private function rescheduleReasonsByRoute(array $routeIds): array
    {
        if ($routeIds === []) {
            return [];
        }

        $byRoute = [];
        $incidents = VehicleIncidentRecord::query()
            ->whereIn('route_id', $routeIds)
            ->whereNotNull('reschedule_date')
            ->get(['route_id', 'type']);
        foreach ($incidents as $incident) {
            $byRoute[$incident->route_id][] = $incident->type;
        }

        return $byRoute;
    }
}
