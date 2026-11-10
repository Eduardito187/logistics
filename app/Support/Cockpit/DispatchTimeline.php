<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use App\Support\Cockpit\Concerns\ReadsCockpitAggregates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Línea de tiempo (Gantt) del DESPACHO del día: un CARRIL por conductor/vehículo, con las paradas de su
 * ruta ubicadas sobre un eje horario común (por su ETA). Hace visible el FLUJO del día —quién sale, cuándo,
 * cuántas paradas, qué pasó— que el pipeline kanban no muestra. Es la pieza que faltaba de la Fase 3.
 *
 * SOLO lectura y tolerante (cada consulta en su try/catch; tabla ausente → vacío). NUNCA expone UUIDs: el
 * carril se identifica por la PLACA del vehículo y el NOMBRE del conductor; la parada, por su referencia de
 * pedido / cliente. Los ids solo arman el enlace al hub.
 */
final class DispatchTimeline
{
    use ReadsCockpitAggregates;

    /** Ventana horaria por defecto cuando no hay ETAs (06:00–20:00). */
    private const int DEFAULT_START_HOUR = 6;

    private const int DEFAULT_END_HOUR = 20;

    /** Máximo de carriles a pintar. */
    private const int MAX_LANES = 24;

    /**
     * Carriles del día (uno por ruta/conductor) + la ventana horaria del eje.
     *
     * @return array{
     *   date: string,
     *   window: array{start: string, end: string, ticks: list<array{label: string, left: float}>},
     *   lanes: list<array{vehicle_id: ?string, vehicle: string, driver: string, stop_count: int, stops: list<array{ref: string, shipment_id: ?string, eta: string, left: float, status: string, tone: string}>}>
     * }
     */
    public function board(?string $date = null): array
    {
        $day = $this->resolveDate($date);
        $routes = $this->routesFor($day);

        // Ventana del eje: del min al max ETA del día (redondeado a la hora), o la ventana por defecto.
        [$start, $end] = $this->axisWindow($day);

        $lanes = [];
        foreach ($routes as $route) {
            $stops = $this->stopsFor((string) $route->id, $start, $end);
            $lanes[] = [
                'vehicle_id' => is_string($route->vehicle_id) ? $route->vehicle_id : null,
                'vehicle' => $this->vehicleLabel($route->vehicle_id),
                'driver' => $this->driverLabel($route->driver_id),
                'stop_count' => count($stops),
                'stops' => $stops,
            ];
        }

        return [
            'date' => $day,
            'window' => [
                'start' => $start->format('H:i'),
                'end' => $end->format('H:i'),
                'ticks' => $this->ticks($start, $end),
            ],
            'lanes' => $lanes,
        ];
    }

    /** Fecha a mostrar: la pedida, o la más reciente con rutas, o hoy. */
    private function resolveDate(?string $date): string
    {
        if (is_string($date) && trim($date) !== '') {
            return trim($date);
        }

        try {
            $latest = DB::table('execution_driver_routes')->max('route_date');
            if (is_string($latest) && $latest !== '') {
                return substr($latest, 0, 10);
            }
        } catch (Throwable) {
            // cae a hoy
        }

        return now()->toDateString();
    }

    /**
     * Rutas del día (máx MAX_LANES), ordenadas por vehículo para un tablero estable.
     *
     * @return list<\stdClass>
     */
    private function routesFor(string $day): array
    {
        try {
            return array_values(DB::table('execution_driver_routes')
                ->whereDate('route_date', $day)
                ->orderBy('vehicle_id')
                ->limit(self::MAX_LANES)
                ->get(['id', 'vehicle_id', 'driver_id'])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Paradas de una ruta ubicadas en el eje por su ETA.
     *
     * @return list<array{ref: string, shipment_id: ?string, eta: string, left: float, status: string, tone: string}>
     */
    private function stopsFor(string $routeId, Carbon $start, Carbon $end): array
    {
        try {
            // Diferencias por TIMESTAMP (segundos epoch): evita el signo de Carbon 3 en diffInSeconds.
            $span = max(1, $end->getTimestamp() - $start->getTimestamp());
            $out = [];
            foreach (DB::table('execution_driver_route_stops')
                ->where('route_id', $routeId)
                ->orderBy('sequence')
                ->get(['shipment_id', 'order_reference', 'customer_name', 'eta', 'status', 'sequence']) as $row) {
                $eta = $this->parseEta($row->eta);
                $left = $eta === null ? 0.0 : round(min(100, max(0, ($eta->getTimestamp() - $start->getTimestamp()) / $span * 100)), 2);
                $status = is_string($row->status) ? $row->status : '';
                $out[] = [
                    'ref' => $this->stopRef($row),
                    'shipment_id' => is_string($row->shipment_id) ? $row->shipment_id : null,
                    'eta' => $eta?->format('H:i') ?? '—',
                    'left' => $left,
                    'status' => $status,
                    'tone' => $this->toneFor($status),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Referencia legible de la parada (pedido o cliente); nunca un UUID. */
    private function stopRef(\stdClass $row): string
    {
        $ref = is_string($row->order_reference) && trim($row->order_reference) !== '' ? trim($row->order_reference) : null;
        $customer = is_string($row->customer_name) && trim($row->customer_name) !== '' ? trim($row->customer_name) : null;

        return $ref ?? $customer ?? '—';
    }

    /** Tono del bloque por estado de la parada. */
    private function toneFor(string $status): string
    {
        $s = strtolower($status);

        return match (true) {
            str_contains($s, 'deliver'), str_contains($s, 'settled'), str_contains($s, 'done') => 'done',
            str_contains($s, 'fail') => 'late',
            str_contains($s, 'skip'), str_contains($s, 'defer') => 'warn',
            default => 'agent',
        };
    }

    /**
     * Ventana del eje: del min al max ETA del día (a la hora), o la ventana por defecto si no hay ETAs.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function axisWindow(string $day): array
    {
        $default = [
            Carbon::parse($day)->setTime(self::DEFAULT_START_HOUR, 0),
            Carbon::parse($day)->setTime(self::DEFAULT_END_HOUR, 0),
        ];

        try {
            $min = DB::table('execution_driver_route_stops as s')
                ->join('execution_driver_routes as r', 'r.id', '=', 's.route_id')
                ->whereDate('r.route_date', $day)
                ->whereNotNull('s.eta')
                ->min('s.eta');
            $max = DB::table('execution_driver_route_stops as s')
                ->join('execution_driver_routes as r', 'r.id', '=', 's.route_id')
                ->whereDate('r.route_date', $day)
                ->whereNotNull('s.eta')
                ->max('s.eta');

            $start = is_string($min) ? $this->parseEta($min)?->startOfHour() : null;
            $end = is_string($max) ? $this->parseEta($max)?->addHour()->startOfHour() : null;

            if ($start !== null && $end !== null && $end->greaterThan($start)) {
                return [$start, $end];
            }
        } catch (Throwable) {
            // cae a la ventana por defecto
        }

        return $default;
    }

    /**
     * Marcas horarias del eje (una por hora), con su posición en %.
     *
     * @return list<array{label: string, left: float}>
     */
    private function ticks(Carbon $start, Carbon $end): array
    {
        $span = max(1, $end->getTimestamp() - $start->getTimestamp());
        $ticks = [];
        $cursor = $start->copy();
        $guard = 0;
        while ($cursor->lessThanOrEqualTo($end) && $guard < 48) {
            $ticks[] = [
                'label' => $cursor->format('H:i'),
                'left' => round(($cursor->getTimestamp() - $start->getTimestamp()) / $span * 100, 2),
            ];
            $cursor->addHour();
            $guard++;
        }

        return $ticks;
    }

    private function parseEta(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** Placa del vehículo (nunca el UUID). */
    private function vehicleLabel(mixed $vehicleId): string
    {
        if (!is_string($vehicleId) || $vehicleId === '') {
            return __('Unassigned vehicle');
        }

        try {
            $plate = DB::table('fleet_vehicles')->where('id', $vehicleId)->value('plate');

            return is_string($plate) && $plate !== '' ? $plate : __('Unassigned vehicle');
        } catch (Throwable) {
            return __('Unassigned vehicle');
        }
    }

    /** Nombre del conductor (nunca el UUID). */
    private function driverLabel(mixed $driverId): string
    {
        if (!is_string($driverId) || $driverId === '') {
            return __('No driver');
        }

        try {
            $name = DB::table('fleet_drivers')->where('id', $driverId)->value('name');

            return is_string($name) && $name !== '' ? $name : __('No driver');
        } catch (Throwable) {
            return __('No driver');
        }
    }
}
