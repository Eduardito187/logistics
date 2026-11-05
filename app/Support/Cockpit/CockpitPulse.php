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
 *   - `delta` va SIEMPRE null y `trend` 'flat': la plataforma todavía no guarda una foto histórica de
 *     estos agregados, así que no hay línea base contra la cual comparar. No se inventa una variación.
 *   - Si una fuente no existe en este entorno, el mosaico vale 0 (nunca rompe la landing).
 */
final class CockpitPulse
{
    use ReadsCockpitAggregates;

    /**
     * Mosaicos del pulso operativo.
     *
     * @return list<array{key: string, label: string, value: int, delta: float|null, trend: string, tone: string, hint: string|null}>
     */
    public function metrics(): array
    {
        $active = $this->activeShipments();
        $inTransit = $this->deliveriesInStatus(DeliveryStatus::InTransit->value);
        $deliveredToday = $this->deliveriesTodayInStatus(DeliveryStatus::Delivered->value);
        $delayed = $this->delayedCount();
        $exceptions = $this->exceptionsCount();
        $escalations = $this->escalationsCount();

        return [
            $this->tile('active_shipments', 'Envíos activos', $active, 'neutral',
                'Envíos que no están en un estado terminal (entregado, fallido o cancelado).'),

            $this->tile('in_transit', 'En tránsito', $inTransit, $inTransit > 0 ? 'positive' : 'neutral',
                'Entregas actualmente en ruta (estado de ejecución IN_TRANSIT).'),

            $this->tile('delivered_today', 'Entregados hoy', $deliveredToday, 'positive',
                'Entregas completadas hoy (hora de la plataforma).'),

            $this->tile('delayed', 'Demorados', $delayed, $delayed > 0 ? 'warning' : 'neutral',
                'Entregas activas cuya ventana comprometida ya venció y que aún no se entregan.'),

            $this->tile('exceptions', 'Excepciones', $exceptions, $exceptions > 0 ? 'danger' : 'neutral',
                'Entregas fallidas de hoy más alertas de telemetría sin reconocer.'),

            $this->tile('escalations', 'Escalamientos', $escalations, $escalations > 0 ? 'danger' : 'neutral',
                'Entregas fallidas de hoy más reservas de cupo vencidas que requieren una decisión.'),
        ];
    }

    /**
     * Arma un mosaico con delta null / trend flat (sin línea base histórica; ver docblock de la clase).
     *
     * @return array{key: string, label: string, value: int, delta: float|null, trend: string, tone: string, hint: string|null}
     */
    private function tile(string $key, string $label, int $value, string $tone, ?string $hint): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'delta' => null,
            'trend' => 'flat',
            'tone' => $tone,
            'hint' => $hint,
        ];
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
