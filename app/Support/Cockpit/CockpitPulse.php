<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use App\Support\Cockpit\Concerns\ReadsCockpitAggregates;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;

/**
 * Tira de KPIs ("pulse strip") de la torre de control: el pulso del día operativo en primitivos, listo
 * para que la UI lo pinte como mosaicos. SOLO lectura, tolerante (tabla ausente → 0) y cacheado ~20s.
 *
 * Cada mosaico es un array con forma fija (contrato con la UI):
 *   ['key', 'label', 'value', 'delta', 'trend', 'tone', 'hint'].
 *
 * Honestidad de los números:
 *   - La VARIACIÓN (delta ▲/▼) se calcula contra la última FOTO HISTÓRICA anterior
 *     (`cockpit_metric_snapshots`, que escribe el comando `cockpit:metrics:snapshot`). Si todavía no hay
 *     línea base (sin snapshot previo), el delta va null: nunca se inventa una variación.
 *   - Si una fuente no existe en este entorno, el mosaico vale 0 (nunca rompe la landing).
 */
final class CockpitPulse
{
    use ReadsCockpitAggregates;

    /**
     * Si "más es mejor" para cada métrica (decide el TONO del delta: verde = mejoró, rojo = empeoró).
     * Las que no son intrínsecamente buenas ni malas (conteos) van null → delta en tono neutro.
     *
     * @var array<string, bool|null>
     */
    private const HIGHER_IS_BETTER = [
        'active_shipments' => null,
        'in_transit' => null,
        'delivered_today' => true,
        'delayed' => false,
        'exceptions' => false,
        'escalations' => false,
    ];

    /**
     * Mosaicos del pulso operativo.
     *
     * @return list<array{key: string, label: string, value: int, delta: string|null, trend: string, tone: string, delta_tone: string, hint: string|null}>
     */
    public function metrics(): array
    {
        $values = $this->rawValues();
        $priors = $this->priorSnapshots();

        $tone = [
            'active_shipments' => 'neutral',
            'in_transit' => $values['in_transit'] > 0 ? 'positive' : 'neutral',
            'delivered_today' => 'positive',
            'delayed' => $values['delayed'] > 0 ? 'warning' : 'neutral',
            'exceptions' => $values['exceptions'] > 0 ? 'danger' : 'neutral',
            'escalations' => $values['escalations'] > 0 ? 'danger' : 'neutral',
        ];

        $labels = [
            'active_shipments' => ['Envíos activos', 'Envíos que no están en un estado terminal (entregado, fallido o cancelado).'],
            'in_transit' => ['En tránsito', 'Entregas actualmente en ruta (estado de ejecución IN_TRANSIT).'],
            'delivered_today' => ['Entregados hoy', 'Entregas completadas hoy (hora de la plataforma).'],
            'delayed' => ['Demorados', 'Entregas activas cuya ventana comprometida ya venció y que aún no se entregan.'],
            'exceptions' => ['Excepciones', 'Entregas fallidas de hoy más alertas de telemetría sin reconocer.'],
            'escalations' => ['Escalamientos', 'Entregas fallidas de hoy más reservas de cupo vencidas que requieren una decisión.'],
        ];

        $tiles = [];
        foreach ($labels as $key => [$label, $hint]) {
            $tiles[] = $this->tile($key, $label, $values[$key], $tone[$key], $hint, $priors[$key] ?? null);
        }

        return $tiles;
    }

    /**
     * Valores crudos de cada métrica del pulso (key => valor). Lo usan tanto los mosaicos como el comando
     * que toma la foto histórica, para que ambos midan EXACTAMENTE lo mismo.
     *
     * @return array<string, int>
     */
    public function rawValues(): array
    {
        return [
            'active_shipments' => $this->activeShipments(),
            'in_transit' => $this->deliveriesInStatus(DeliveryStatus::InTransit->value),
            'delivered_today' => $this->deliveriesTodayInStatus(DeliveryStatus::Delivered->value),
            'delayed' => $this->delayedCount(),
            'exceptions' => $this->exceptionsCount(),
            'escalations' => $this->escalationsCount(),
        ];
    }

    /**
     * Arma un mosaico, calculando el delta contra el último snapshot ANTERIOR si existe.
     *
     * @return array{key: string, label: string, value: int, delta: string|null, trend: string, tone: string, delta_tone: string, hint: string|null}
     */
    private function tile(string $key, string $label, int $value, string $tone, ?string $hint, ?int $prior): array
    {
        $delta = null;
        $trend = 'flat';
        $deltaTone = 'neutral';

        if ($prior !== null) {
            $diff = $value - $prior;
            $trend = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat');
            $sign = $diff > 0 ? '+' : ($diff < 0 ? '−' : '');
            $delta = $sign.abs($diff);

            $higherIsBetter = self::HIGHER_IS_BETTER[$key] ?? null;
            if ($diff !== 0 && $higherIsBetter !== null) {
                $improved = $higherIsBetter ? $diff > 0 : $diff < 0;
                $deltaTone = $improved ? 'positive' : 'negative';
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'delta' => $delta,
            'trend' => $trend,
            'tone' => $tone,
            'delta_tone' => $deltaTone,
            'hint' => $hint,
        ];
    }

    /**
     * Último valor de CADA métrica en una foto histórica ANTERIOR a hoy (key => valor). Tolerante: si la
     * tabla no existe o no hay base, devuelve [] y los deltas quedan null.
     *
     * @return array<string, int>
     */
    private function priorSnapshots(): array
    {
        try {
            /** @var array<string, int> $out */
            $out = [];
            $rows = DB::table('cockpit_metric_snapshots')
                ->where('captured_on', '<', $this->startOfToday()->toDateString())
                ->orderByDesc('captured_on')
                ->get(['metric_key', 'value', 'captured_on']);

            foreach ($rows as $row) {
                $metric = (string) $row->metric_key;
                // El primero por métrica es el más reciente anterior a hoy (orden desc).
                if (!array_key_exists($metric, $out)) {
                    $out[$metric] = (int) $row->value;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Envíos activos: los que NO están en un estado terminal. Cero si la tabla no existe.
     */
    private function activeShipments(): int
    {
        try {
            return $this->remember('pulse:active_shipments', fn (): int => (int) DB::table('shipments')
                ->whereNotIn('status', CockpitStates::SHIPMENT_TERMINAL)
                ->count());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Entregas en un estado dado (sin acotar a hoy). Cero si la tabla no existe.
     */
    private function deliveriesInStatus(string $status): int
    {
        try {
            return $this->remember('pulse:delivery_status:'.$status, fn (): int => (int) DB::table('execution_deliveries')
                ->where('status', $status)
                ->count());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Entregas en un estado dado, tocadas HOY (updated_at). Cero si la tabla no existe.
     */
    private function deliveriesTodayInStatus(string $status): int
    {
        try {
            $since = $this->startOfToday();

            return $this->remember(
                'pulse:delivery_status_today:'.$status.':'.$since->toDateString(),
                fn (): int => (int) DB::table('execution_deliveries')
                    ->where('status', $status)
                    ->where('updated_at', '>=', $since)
                    ->count(),
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Atrasadas = entregas activas cuya ventana comprometida ya venció (ver atRiskDeliveriesQuery()).
     */
    private function delayedCount(): int
    {
        try {
            return $this->remember('pulse:delayed', fn (): int => $this->atRiskDeliveriesQuery()->count());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Excepciones = entregas fallidas hoy + alertas de telemetría sin reconocer.
     */
    private function exceptionsCount(): int
    {
        return $this->deliveriesTodayInStatus(DeliveryStatus::Failed->value) + $this->unacknowledgedAlerts();
    }

    /**
     * Escalamientos = entregas fallidas hoy + reservas de promesa vencidas (requieren una decisión).
     */
    private function escalationsCount(): int
    {
        return $this->deliveriesTodayInStatus(DeliveryStatus::Failed->value) + $this->expiredPromises();
    }

    /**
     * Alertas de telemetría sin reconocer (acknowledged_at IS NULL). Cero si la tabla no existe.
     */
    private function unacknowledgedAlerts(): int
    {
        try {
            return $this->remember('pulse:unack_alerts', fn (): int => (int) DB::table('telemetry_alerts')
                ->whereNull('acknowledged_at')
                ->count());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Reservas de promesa vencidas: ya expiradas, o reservadas con la ventana ya cumplida. Cero si falta.
     */
    private function expiredPromises(): int
    {
        try {
            return $this->remember('pulse:expired_promises', fn (): int => (int) DB::table('promises')
                ->where(function ($q): void {
                    $q->where('status', 'EXPIRED')
                        ->orWhere(function ($q2): void {
                            $q2->where('status', 'RESERVED')->where('reserved_until', '<', now());
                        });
                })
                ->count());
        } catch (\Throwable) {
            return 0;
        }
    }
}
