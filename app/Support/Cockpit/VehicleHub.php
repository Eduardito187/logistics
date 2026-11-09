<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Execution\Application\ReadModel\RouteStopView;
use Logistics\Fleet\Application\Query\ListDocuments\ListDocuments;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\ReadModel\DocumentView;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\FleetOps\Application\Query\GetVehicleCostSummary\GetVehicleCostSummary;
use Logistics\FleetOps\Application\Query\GetVehicleUtilization\GetVehicleUtilization;
use Logistics\FleetOps\Application\ReadModel\UtilizationView;
use Logistics\FleetOps\Application\ReadModel\VehicleCostSummaryView;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Application\Query\ListAlerts\ListAlerts;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;
use Throwable;

/**
 * Compositor de LECTURA del "hub 360" de un vehículo: agrega, por vehículo, lo que vive disperso en varios
 * bounded contexts —telemetría en vivo, ruta/entregas de hoy (execution), costos/utilización/documentos
 * (fleetops + fleet), alertas de mal uso e incidentes— en una sola estructura primitiva para la vista.
 *
 * Prefiere los QueryHandlers por el QueryBus (CQRS) y cae a lectura directa SOLO donde no hay query
 * (chofer del día, incidentes, serie de km). Es tolerante como el resto del cockpit: cada fuente va en su
 * try/catch y degrada a null/[]/0 si falta una tabla o un contexto; nunca rompe la página. SOLO lectura.
 * NUNCA expone un UUID como texto: las referencias son legibles (placa, nombre, nº de pedido, tipo de doc).
 */
final class VehicleHub
{
    /** Ventana de costos/utilización por defecto: últimos 30 días. */
    private const WINDOW_DAYS = 30;

    /** Días hacia atrás de la serie de km del gráfico. */
    private const KM_SERIES_DAYS = 14;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * Todo lo que la vista del hub necesita, ya en primitivos y tolerante.
     *
     * @return array{
     *   live: array{speed_kph: float|null, moving: bool|null, ignition: bool|null, recorded_at: string|null}|null,
     *   assignment: array{driver_name: string|null, driver_id: string|null, route: array{status: string, total: int, completed: int, remaining: int, stops: list<array{label: string, eta: string|null, state: string}>}|null},
     *   cost: array{fuel_cents: int, maintenance_cents: int, expenses_cents: int, total_cents: int, currency: string, kilometers: int, cost_per_km_cents: int|null}|null,
     *   utilization: array{km: int, departures: int, hours: float, last_odometer_km: int|null, last_odometer_read_at: string|null}|null,
     *   compliance: array{expired: int, due_soon: int, upcoming: int},
     *   documents: list<array{type: string, reference: string|null, expires_on: string, state: string}>,
     *   alerts: list<array{type: string, severity: string, occurred_at: string}>,
     *   incidents: list<array{type: string, severity: string, note: string|null, occurred_at: string}>,
     *   km_series: array{labels: list<string>, values: list<float>},
     * }
     */
    public function overview(string $vehicleId): array
    {
        return [
            'live' => $this->live($vehicleId),
            'assignment' => $this->assignment($vehicleId),
            'cost' => $this->cost($vehicleId),
            'utilization' => $this->utilization($vehicleId),
            'compliance' => $this->compliance($vehicleId),
            'documents' => $this->documents($vehicleId),
            'alerts' => $this->alerts($vehicleId),
            'incidents' => $this->incidents($vehicleId),
            'km_series' => $this->kmSeries($vehicleId),
        ];
    }

    /**
     * Posición en vivo (velocidad/movimiento/encendido) del vehículo, o null si no hay telemetría.
     *
     * @return array{speed_kph: float|null, moving: bool|null, ignition: bool|null, recorded_at: string|null}|null
     */
    private function live(string $vehicleId): ?array
    {
        try {
            /** @var list<VehicleLiveView> $views */
            $views = $this->queryBus->ask(new GetLiveFleetPositions);
            foreach ($views as $view) {
                if ($view->vehicleId === $vehicleId) {
                    return [
                        'speed_kph' => $view->speedKph,
                        'moving' => $view->moving,
                        'ignition' => $view->ignition,
                        'recorded_at' => $view->recordedAt,
                    ];
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Asignación de hoy: ruta vigente (execution) + chofer del día. Ruta null si no hay ninguna abierta.
     *
     * @return array{driver_name: string|null, driver_id: string|null, route: array{status: string, total: int, completed: int, remaining: int, stops: list<array{label: string, eta: string|null, state: string}>}|null}
     */
    private function assignment(string $vehicleId): array
    {
        $route = null;
        try {
            $view = $this->queryBus->ask(new GetDriverRoute($vehicleId));
            if ($view instanceof DriverRouteView) {
                $route = [
                    'status' => $view->status,
                    'total' => $view->totalStops,
                    'completed' => $view->completedStops,
                    'remaining' => $view->stopsRemaining,
                    'stops' => $this->mapStops($view->stops),
                ];
            }
        } catch (Throwable) {
            $route = null;
        }

        $driver = $this->driverOfToday($vehicleId);

        return [
            'driver_name' => $driver['name'] ?? null,
            'driver_id' => $driver['id'] ?? null,
            'route' => $route,
        ];
    }

    /**
     * Mapea las paradas de la ruta a la forma de la UI (pipeline mini).
     *
     * @param  list<RouteStopView>                                         $stops
     * @return list<array{label: string, eta: string|null, state: string}>
     */
    private function mapStops(array $stops): array
    {
        $out = [];
        foreach ($stops as $stop) {
            $label = $this->nonEmpty($stop->customerName)
                ?? $this->nonEmpty($stop->orderReference)
                ?? ('Parada '.$stop->sequence);

            $out[] = [
                'label' => $label,
                'eta' => $this->isoToShort($stop->etaAt),
                'state' => $this->stopState($stop),
            ];
        }

        return $out;
    }

    /** Estado de una parada para el pipeline: entregada/fallida/actual/pendiente. */
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

    /**
     * Chofer de hoy del vehículo (hueco: no hay query único). Se toma de la última salida de fleetops del
     * día y se resuelve a nombre legible desde fleet_drivers. Null si no hay salida registrada hoy.
     */
    /**
     * Chofer de hoy del vehículo (id + nombre, para enlazar a su ficha 360). Hueco: no hay query único;
     * se toma de la última salida de fleetops del día y se resuelve el nombre desde fleet_drivers.
     *
     * @return array{id: string, name: string}|null
     */
    private function driverOfToday(string $vehicleId): ?array
    {
        try {
            $driverId = DB::table('fleetops_departures')
                ->where('vehicle_id', $vehicleId)
                ->whereDate('departed_at', now()->toDateString())
                ->orderByDesc('departed_at')
                ->value('driver_id');

            if (!is_string($driverId) || $driverId === '') {
                return null;
            }

            $name = DB::table('fleet_drivers')->where('id', $driverId)->value('name');

            return is_string($name) && $name !== '' ? ['id' => $driverId, 'name' => $name] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resumen de costos de los últimos 30 días (fleetops), o null si el contexto no está disponible.
     *
     * @return array{fuel_cents: int, maintenance_cents: int, expenses_cents: int, total_cents: int, currency: string, kilometers: int, cost_per_km_cents: int|null}|null
     */
    private function cost(string $vehicleId): ?array
    {
        try {
            $view = $this->queryBus->ask(new GetVehicleCostSummary(
                vehicleId: $vehicleId,
                from: now()->subDays(self::WINDOW_DAYS)->toDateString(),
                to: now()->toDateString(),
            ));
            if (!$view instanceof VehicleCostSummaryView) {
                return null;
            }

            return [
                'fuel_cents' => $view->fuelCents,
                'maintenance_cents' => $view->maintenanceCents,
                'expenses_cents' => $view->expensesCents,
                'total_cents' => $view->totalCents,
                'currency' => $view->currency,
                'kilometers' => $view->kilometers,
                'cost_per_km_cents' => $view->costPerKmCents,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Utilización de los últimos 30 días (km, salidas, horas, último odómetro), o null.
     *
     * @return array{km: int, departures: int, hours: float, last_odometer_km: int|null, last_odometer_read_at: string|null}|null
     */
    private function utilization(string $vehicleId): ?array
    {
        try {
            $view = $this->queryBus->ask(new GetVehicleUtilization(
                vehicleId: $vehicleId,
                from: now()->subDays(self::WINDOW_DAYS)->toDateString(),
                to: now()->toDateString(),
            ));
            if (!$view instanceof UtilizationView) {
                return null;
            }

            return [
                'km' => $view->kmTraveled,
                'departures' => $view->departuresCount,
                'hours' => $view->hoursOnRoad,
                'last_odometer_km' => $view->lastOdometerKm,
                'last_odometer_read_at' => $this->isoToShort($view->lastOdometerReadAt),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Conteo de documentos del vehículo por umbral de vencimiento (vencidos / ≤7d / ≤30d).
     *
     * @return array{expired: int, due_soon: int, upcoming: int}
     */
    private function compliance(string $vehicleId): array
    {
        $counts = ['expired' => 0, 'due_soon' => 0, 'upcoming' => 0];

        try {
            /** @var list<ExpiringDocumentView> $views */
            $views = $this->queryBus->ask(new ListExpiringDocuments(days: self::WINDOW_DAYS));
            $ref = new DateTimeImmutable('today');
            foreach ($views as $view) {
                if ($view->ownerType !== 'vehicle' || $view->ownerId !== $vehicleId) {
                    continue;
                }
                $threshold = $view->thresholdAt($ref);
                if ($threshold === ExpiringDocumentView::THRESHOLD_EXPIRED) {
                    $counts['expired']++;
                } elseif ($threshold === ExpiringDocumentView::THRESHOLD_DUE_SOON) {
                    $counts['due_soon']++;
                } elseif ($threshold === ExpiringDocumentView::THRESHOLD_UPCOMING) {
                    $counts['upcoming']++;
                }
            }
        } catch (Throwable) {
            // sin contexto de documentos: queda en cero.
        }

        return $counts;
    }

    /**
     * Documentos del vehículo (tipo, referencia, vencimiento, estado), ordenados por vencimiento.
     *
     * @return list<array{type: string, reference: string|null, expires_on: string, state: string}>
     */
    private function documents(string $vehicleId): array
    {
        try {
            /** @var list<DocumentView> $views */
            $views = $this->queryBus->ask(new ListDocuments(ownerType: 'vehicle'));
            $out = [];
            foreach ($views as $view) {
                if ($view->ownerId !== $vehicleId) {
                    continue;
                }
                $out[] = [
                    'type' => $view->documentType,
                    'reference' => $view->reference,
                    'expires_on' => $view->expiresOn,
                    'state' => $this->expiryState($view->expiresOn),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Alertas de mal uso (telemetría) recientes del vehículo.
     *
     * @return list<array{type: string, severity: string, occurred_at: string}>
     */
    private function alerts(string $vehicleId): array
    {
        try {
            $result = $this->queryBus->ask(new ListAlerts(vehicleId: $vehicleId, limit: 8));
            // Con `limit` el handler devuelve una Page (items + total); sin él, una lista plana.
            /** @var list<AlertView> $views */
            $views = $result instanceof Page ? $result->items : $result;
            $out = [];
            foreach ($views as $view) {
                $out[] = [
                    'type' => $view->type,
                    'severity' => $view->severity,
                    'occurred_at' => $this->isoToShort($view->occurredAt) ?? $view->occurredAt,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Incidentes/averías operativas del vehículo (hueco de lectura: sin QueryHandler → lectura directa).
     *
     * @return list<array{type: string, severity: string, note: string|null, occurred_at: string}>
     */
    private function incidents(string $vehicleId): array
    {
        try {
            return array_values(DB::table('execution_vehicle_incidents')
                ->where('vehicle_id', $vehicleId)
                ->orderByDesc('occurred_at')
                ->limit(8)
                ->get(['type', 'severity', 'note', 'occurred_at'])
                ->map(fn ($r): array => [
                    'type' => (string) $r->type,
                    'severity' => (string) $r->severity,
                    'note' => $this->nonEmpty(is_string($r->note) ? $r->note : null),
                    'occurred_at' => $this->isoToShort(is_string($r->occurred_at) ? $r->occurred_at : null)
                        ?? (string) $r->occurred_at,
                ])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Serie de km por día de los últimos 14 días (telemetry_trips), para el gráfico de actividad.
     *
     * @return array{labels: list<string>, values: list<float>}
     */
    private function kmSeries(string $vehicleId): array
    {
        $labels = [];
        $values = [];
        $byDay = [];

        try {
            $rows = DB::table('telemetry_trips')
                ->where('vehicle_id', $vehicleId)
                ->where('started_at', '>=', now()->subDays(self::KM_SERIES_DAYS - 1)->startOfDay())
                ->selectRaw('date(started_at) as d, sum(distance_m) as m')
                ->groupByRaw('date(started_at)')
                ->get();

            foreach ($rows as $row) {
                $byDay[(string) $row->d] = round(((float) $row->m) / 1000, 1);
            }
        } catch (Throwable) {
            $byDay = [];
        }

        // Rellena los 14 días (incluso los sin viajes = 0) para que el eje sea continuo.
        for ($i = self::KM_SERIES_DAYS - 1; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $labels[] = $day->isoFormat('DD');
            $values[] = $byDay[$day->toDateString()] ?? 0.0;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** Estado de vencimiento de un documento por su fecha (vencido / por vencer ≤7d / próximo ≤30d / ok). */
    private function expiryState(string $expiresOn): string
    {
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $expiresOn);
        if ($expiry === false) {
            return 'ok';
        }

        $today = new DateTimeImmutable('today');
        if ($expiry < $today) {
            return 'expired';
        }

        $daysLeft = (int) $today->diff($expiry)->format('%a');
        if ($daysLeft <= 7) {
            return 'due_soon';
        }
        if ($daysLeft <= 30) {
            return 'upcoming';
        }

        return 'ok';
    }

    /** ISO 8601 → "19 oct 09:00" legible, o null si viene vacío/ilegible. */
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

    /** Devuelve el string si no está vacío (trim); null en caso contrario. */
    private function nonEmpty(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
