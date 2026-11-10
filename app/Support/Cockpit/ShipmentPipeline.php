<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lectura del "pipeline de última milla": agrupa los envíos por estado del ciclo de vida en columnas tipo
 * tablero (recibido → planificado → en ejecución → entregado / fallido), con el conteo por columna y las
 * tarjetas más relevantes de cada una. Hace visible el FLUJO operativo, no una lista plana.
 *
 * SOLO lectura y tolerante (cada consulta en su try/catch; si falta una tabla/columna, degrada a 0/[]).
 * Marca "atrasada" cada tarjeta cuya ventana comprometida venció y sigue sin entregar. En la columna "en
 * ejecución" resuelve la placa del vehículo asignado (lote). NUNCA expone un UUID como texto: la tarjeta
 * lleva el id solo para armar el enlace al hub del envío.
 */
final class ShipmentPipeline
{
    /** Máximo de tarjetas por columna. */
    private const PER_COLUMN = 10;

    /**
     * Definición de columnas: estado → [etiqueta, tono]. El orden es el del flujo.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const COLUMNS = [
        'RECEIVED' => ['Recibidos', 'agent'],
        'PLANNED' => ['Planificados', 'agent'],
        'IN_EXECUTION' => ['En ejecución', 'warn'],
        'DELIVERED' => ['Entregados', 'done'],
        'FAILED' => ['Fallidos', 'late'],
    ];

    /** Estados en los que un envío sigue "vivo" (su ventana vencida lo vuelve atrasado). */
    private const ACTIVE = ['RECEIVED', 'PLANNED', 'IN_EXECUTION'];

    /**
     * Columnas del pipeline con conteo y tarjetas.
     *
     * @return list<array{
     *   status: string,
     *   label: string,
     *   tone: string,
     *   count: int,
     *   late_count: int,
     *   cards: list<array{id: string, ref: string, customer: string, window: string|null, vehicle_plate: string|null, late: bool}>
     * }>
     */
    public function columns(): array
    {
        $counts = $this->countsByStatus();
        $out = [];

        foreach (self::COLUMNS as $status => [$label, $tone]) {
            $cards = $this->cards($status);
            $lateCount = 0;
            foreach ($cards as $card) {
                if ($card['late']) {
                    $lateCount++;
                }
            }

            $out[] = [
                'status' => $status,
                'label' => $label,
                'tone' => $tone,
                'count' => $counts[$status] ?? 0,
                'late_count' => $lateCount,
                'cards' => $cards,
            ];
        }

        return $out;
    }

    /**
     * Conteo total de envíos por estado.
     *
     * @return array<string, int>
     */
    private function countsByStatus(): array
    {
        try {
            /** @var array<string, int> $counts */
            $counts = DB::table('shipments')
                ->selectRaw('status, count(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->map(static fn ($c): int => (int) $c)
                ->all();

            return $counts;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Tarjetas de una columna (los envíos más recientes del estado), enriquecidas.
     *
     * @return list<array{id: string, ref: string, customer: string, window: string|null, vehicle_plate: string|null, late: bool}>
     */
    private function cards(string $status): array
    {
        try {
            $rows = DB::table('shipments')
                ->where('status', $status)
                ->orderByRaw('coalesce(delivery_date, created_at::date) desc')
                ->orderByDesc('created_at')
                ->limit(self::PER_COLUMN)
                ->get(['id', 'external_id', 'customer_name', 'delivery_date', 'delivery_window_start', 'delivery_window_end']);

            if ($rows->isEmpty()) {
                return [];
            }

            $plates = $status === 'IN_EXECUTION'
                ? $this->platesForShipments(array_values($rows->pluck('id')->map(static fn ($v): string => (string) $v)->all()))
                : [];

            $now = Carbon::now();
            $isActive = in_array($status, self::ACTIVE, true);

            return array_values($rows->map(function ($r) use ($plates, $now, $isActive): array {
                $id = (string) $r->id;

                return [
                    'id' => $id,
                    'ref' => (string) ($r->external_id ?: $r->customer_name ?: 'Envío'),
                    'customer' => (string) ($r->customer_name ?: '—'),
                    'window' => $this->window($r->delivery_date, $r->delivery_window_start, $r->delivery_window_end),
                    'vehicle_plate' => $plates[$id] ?? null,
                    'late' => $isActive && $this->isLate($r->delivery_date, $r->delivery_window_end, $now),
                ];
            })->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Placa del vehículo asignado por envío (execution_deliveries), en lote.
     *
     * @param  list<string>          $shipmentIds
     * @return array<string, string>
     */
    private function platesForShipments(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        try {
            $byShipment = DB::table('execution_deliveries')
                ->whereIn('shipment_id', $shipmentIds)
                ->whereNotNull('vehicle_id')
                ->pluck('vehicle_id', 'shipment_id');

            $vehicleIds = array_values(array_unique(array_map(static fn ($v): string => (string) $v, $byShipment->all())));
            if ($vehicleIds === []) {
                return [];
            }

            $plates = DB::table('fleet_vehicles')->whereIn('id', $vehicleIds)->pluck('plate', 'id');

            $out = [];
            foreach ($byShipment as $shipmentId => $vehicleId) {
                $plate = $plates[(string) $vehicleId] ?? null;
                if (is_string($plate) && $plate !== '') {
                    $out[(string) $shipmentId] = $plate;
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Franja comprometida legible ("05 nov 11:00–16:00"), o null si no hay ventana. */
    private function window(mixed $date, mixed $start, mixed $end): ?string
    {
        if (!is_string($start) || $start === '' || !is_string($end) || $end === '') {
            return null;
        }

        $day = '';
        if (is_string($date) && $date !== '') {
            try {
                $day = Carbon::parse($date)->isoFormat('DD MMM').' ';
            } catch (Throwable) {
                $day = '';
            }
        }

        return trim($day.$start.'–'.$end);
    }

    /** ¿La ventana comprometida ya venció? (delivery_date + window_end < ahora). */
    private function isLate(mixed $date, mixed $end, Carbon $now): bool
    {
        if (!is_string($date) || $date === '' || !is_string($end) || $end === '') {
            return false;
        }

        try {
            return Carbon::parse($date.' '.$end)->lessThan($now);
        } catch (Throwable) {
            return false;
        }
    }
}
