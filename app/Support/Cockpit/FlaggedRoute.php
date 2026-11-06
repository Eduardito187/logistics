<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use App\Support\Cockpit\Concerns\ReadsCockpitAggregates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;
use Logistics\Telemetry\Presentation\Filament\Support\VehiclePlates;

/**
 * Tarjeta "héroe" de la torre de control: el ÚNICO envío/ruta más urgente que necesita acción ahora
 * (la peor violación de ventana). Devuelve null si no hay ninguno en riesgo.
 *
 * Cruza datos reales: la franja comprometida (shipments) + el estado de ejecución (execution_deliveries)
 * + la secuencia de paradas con ETA (execution_driver_route_stops) + la posición EN VIVO del vehículo
 * (telemetría vía QueryBus → VehicleLiveView: velocidad/movimiento). SOLO lectura y tolerante: cualquier
 * fuente ausente degrada su campo a null, nunca rompe la landing.
 *
 * NO fabrica datos inexistentes: no hay temperatura de refrigeración ni nivel de combustible en vivo, así
 * que esas claves NO existen. `load` solo aparece si hubiera conteo de bultos real; hoy no lo hay → null.
 * Ningún UUID visible: la referencia es el id externo del pedido; el vehículo es su placa; el chofer su
 * nombre.
 */
final class FlaggedRoute
{
    use ReadsCockpitAggregates;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * El envío en riesgo con la peor violación de ventana, enriquecido, o null si no hay ninguno.
     *
     * @return array{
     *   shipment_id: string,
     *   shipment_ref: string,
     *   origin: string|null,
     *   destination: string|null,
     *   driver_name: string|null,
     *   vehicle_label: string|null,
     *   window_start: string|null,
     *   window_end: string|null,
     *   eta: string|null,
     *   eta_delay_minutes: int|null,
     *   speed_kph: float|null,
     *   moving: bool|null,
     *   stops: list<array{label: string, eta: string|null, state: string}>,
     *   flagged_reason: string,
     * }|null
     */
    public function current(): ?array
    {
        $row = $this->worstAtRisk();
        if ($row === null) {
            return null;
        }

        $shipmentId = (string) $row->shipment_id;
        $vehicleId = (string) $row->vehicle_id;

        $windowStart = $this->combine($row->delivery_date, $row->delivery_window_start);
        $windowEnd = $this->combine($row->delivery_date, $row->delivery_window_end);

        $stopsData = $this->routeStops($vehicleId);
        $eta = $this->etaForShipment($stopsData, $shipmentId);
        $live = $this->liveVehicle($vehicleId);

        $reference = $eta ?? now();
        $delayMinutes = $windowEnd !== null ? (int) round($windowEnd->diffInMinutes($reference, false)) : null;

        return [
            'shipment_id' => $shipmentId,
            'shipment_ref' => (string) ($row->external_id ?? $shipmentId),
            'origin' => null,
            'destination' => $this->nullableString($row->address_text),
            'driver_name' => $this->driverName($vehicleId),
            'vehicle_label' => VehiclePlates::label($vehicleId),
            'window_start' => $windowStart?->toIso8601String(),
            'window_end' => $windowEnd?->toIso8601String(),
            'eta' => $eta?->toIso8601String(),
            'eta_delay_minutes' => $delayMinutes,
            'speed_kph' => $live?->speedKph,
            'moving' => $live?->moving,
            'stops' => $this->mapStops($stopsData),
            'flagged_reason' => $this->reason((string) $row->status, $windowEnd),
        ];
    }

    /**
     * La entrega en riesgo con la ventana vencida HACE MÁS TIEMPO (peor violación), o null.
     */
    private function worstAtRisk(): ?\stdClass
    {
        try {
            return $this->atRiskDeliveriesQuery()
                ->select([
                    'd.shipment_id',
                    'd.vehicle_id',
                    'd.status',
                    's.external_id',
                    's.address_text',
                    's.delivery_date',
                    's.delivery_window_start',
                    's.delivery_window_end',
                ])
                ->orderByRaw('(s.delivery_date + s.delivery_window_end::time) asc')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Paradas de la ruta de hoy del vehículo, en orden de secuencia. Vacío si no hay ruta/tabla.
     *
     * @return array<int, \stdClass>
     */
    private function routeStops(string $vehicleId): array
    {
        try {
            return DB::table('execution_driver_route_stops as st')
                ->join('execution_driver_routes as r', 'r.id', '=', 'st.route_id')
                ->where('r.vehicle_id', $vehicleId)
                ->where('r.route_date', now()->toDateString())
                ->orderBy('st.sequence')
                ->select(['st.shipment_id', 'st.order_reference', 'st.customer_name', 'st.sequence', 'st.eta', 'st.settled_at'])
                ->get()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * ETA de la parada del envío buscado, como Carbon, o null.
     *
     * @param array<int, \stdClass> $stops
     */
    private function etaForShipment(array $stops, string $shipmentId): ?Carbon
    {
        foreach ($stops as $stop) {
            if ((string) $stop->shipment_id === $shipmentId && $stop->eta !== null) {
                return $this->toCarbon($stop->eta);
            }
        }

        return null;
    }

    /**
     * Mapea las paradas a la forma de la UI: la primera sin cerrar es la "actual"; las cerradas, "done".
     *
     * @param  array<int, \stdClass>                                       $stops
     * @return list<array{label: string, eta: string|null, state: string}>
     */
    private function mapStops(array $stops): array
    {
        $currentAssigned = false;
        $out = [];

        foreach ($stops as $stop) {
            if ($stop->settled_at !== null) {
                $state = 'done';
            } elseif (!$currentAssigned) {
                $state = 'current';
                $currentAssigned = true;
            } else {
                $state = 'upcoming';
            }

            $label = $this->nullableString($stop->customer_name)
                ?? $this->nullableString($stop->order_reference)
                ?? ('Parada '.(int) $stop->sequence);

            $out[] = [
                'label' => $label,
                'eta' => $this->toCarbon($stop->eta)?->isoFormat('DD MMM HH:mm'),
                'state' => $state,
            ];
        }

        return $out;
    }

    /**
     * Posición en vivo del vehículo (velocidad/movimiento) desde el lado de lectura CQRS, o null.
     */
    private function liveVehicle(string $vehicleId): ?VehicleLiveView
    {
        try {
            /** @var list<VehicleLiveView> $views */
            $views = $this->queryBus->ask(new GetLiveFleetPositions);
            foreach ($views as $view) {
                if ($view->vehicleId === $vehicleId) {
                    return $view;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Nombre del chofer de la ruta de hoy del vehículo (fleet_drivers.name), o null. Lectura tolerante.
     */
    private function driverName(string $vehicleId): ?string
    {
        try {
            $driverId = DB::table('execution_driver_routes')
                ->where('vehicle_id', $vehicleId)
                ->where('route_date', now()->toDateString())
                ->value('driver_id');

            if (!is_string($driverId) || $driverId === '') {
                return null;
            }

            $name = DB::table('fleet_drivers')->where('id', $driverId)->value('name');

            return is_string($name) && $name !== '' ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Motivo del banderazo, legible para el operador.
     */
    private function reason(string $status, ?Carbon $windowEnd): string
    {
        $estado = $this->statusLabel($status);

        if ($windowEnd === null) {
            return "Ventana de entrega vencida; el envío sigue en {$estado}.";
        }

        $minutes = abs((int) round($windowEnd->diffInMinutes(now(), false)));

        return 'Ventana cerrada hace '.$this->humanMinutes($minutes)."; el envío sigue en {$estado}.";
    }

    /** Minutos → legible (16d 10h / 2h 05m / 45m). */
    private function humanMinutes(int $m): string
    {
        if ($m < 60) {
            return $m.' min';
        }
        if ($m < 1440) {
            return intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
        }

        return intdiv($m, 1440).'d '.intdiv($m % 1440, 60).'h';
    }

    /** Estado de entrega (código del dominio) en etiqueta legible en español. */
    private function statusLabel(string $status): string
    {
        return match (strtoupper($status)) {
            'PENDING' => 'pendiente',
            'PREPARED' => 'preparado',
            'LOADED' => 'cargado',
            'IN_TRANSIT' => 'en tránsito',
            'DELIVERED' => 'entregado',
            'FAILED' => 'fallido',
            default => strtolower($status),
        };
    }

    /**
     * Combina una fecha 'Y-m-d' y una hora 'HH:MM' en un Carbon en la zona de la plataforma, o null.
     */
    private function combine(mixed $date, mixed $clock): ?Carbon
    {
        if (!is_string($date) || $date === '' || !is_string($clock) || $clock === '') {
            return null;
        }

        try {
            return Carbon::parse($date.' '.$clock);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Convierte un valor de fecha/hora (string de Postgres) en Carbon, o null.
     */
    private function toCarbon(mixed $value): ?Carbon
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Devuelve el string si no está vacío; si no, null.
     */
    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
