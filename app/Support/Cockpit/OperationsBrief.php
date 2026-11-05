<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use App\Support\Cockpit\Concerns\ReadsCockpitAggregates;
use Illuminate\Support\Facades\DB;

/**
 * Resumen de inteligencia operativa derivada: el núcleo del cockpit "Control Tower". Cruza la franja
 * comprometida (promise/shipments) con el estado de ejecución para decir, con datos reales, cuánto de
 * la red está EN RIESGO, por qué, y qué acciones seguras puede aprobar el operador.
 *
 * SOLO lectura, tolerante (fuente ausente → se omite esa señal) y cacheado ~20s. Forma primitiva fija
 * (contrato con la UI); no inventa señales: cada causa y cada propuesta sale de una tabla que existe.
 *
 * Derivación de "en riesgo" (honesta y única, ver {@see ReadsCockpitAggregates::atRiskDeliveriesQuery()}):
 * una entrega está en riesgo si sigue ACTIVA (no DELIVERED/FAILED) y su ventana comprometida
 * (shipments.delivery_date + delivery_window_end) ya venció. `on_plan = activos − en_riesgo` (piso 0).
 */
final class OperationsBrief
{
    use ReadsCockpitAggregates;

    /**
     * @return array{
     *   at_risk_count: int,
     *   on_plan_count: int,
     *   headline: string,
     *   causes: list<array{key: string, label: string, count: int, weight: float}>,
     *   proposals: list<array{id: string, type: string, label: string, count: int, actionable: bool, action: string|null}>,
     * }
     */
    public function summary(): array
    {
        $active = $this->activeShipments();
        $atRisk = $this->atRiskCount();
        $onPlan = max(0, $active - $atRisk);

        $causes = $this->causes();
        $proposals = $this->proposals();

        return [
            'at_risk_count' => $atRisk,
            'on_plan_count' => $onPlan,
            'headline' => $this->headline($atRisk),
            'causes' => $causes,
            'proposals' => $proposals,
        ];
    }

    /**
     * Propuestas de acción del operador. Cada una lleva un `action` (clave de método del
     * {@see OperationsActionService}) SOLO si hay un Command real detrás; las demás son consultivas
     * (`actionable=false`, `action=null`). Compartida por la UI y por el ActionService.
     *
     * @return list<array{id: string, type: string, label: string, count: int, actionable: bool, action: string|null}>
     */
    public function proposals(): array
    {
        $atRisk = $this->atRiskCount();
        $late = $this->lateRoutesCount();
        $expired = $this->expiredPromisesCount();

        return [
            [
                'id' => 'notify_delayed_customers',
                'type' => 'notify',
                'label' => "Notificar a {$atRisk} clientes sobre un retraso en la entrega",
                'count' => $atRisk,
                'actionable' => $atRisk > 0,
                'action' => $atRisk > 0 ? 'notify_delayed_customers' : null,
            ],
            [
                'id' => 'replan_late_routes',
                'type' => 'replan',
                'label' => "Replanificar {$late} envíos atrasados a la próxima ventana",
                'count' => $late,
                'actionable' => $late > 0,
                'action' => $late > 0 ? 'replan_late_routes' : null,
            ],
            [
                'id' => 'release_expired_reservations',
                'type' => 'reschedule',
                'label' => "Liberar {$expired} reservas de cupo vencidas",
                'count' => $expired,
                'actionable' => $expired > 0,
                'action' => $expired > 0 ? 'release_expired_reservations' : null,
            ],
        ];
    }

    /**
     * Causas atribuidas al riesgo, rankeadas desc por conteo. Solo se incluye una causa cuya tabla
     * existe y cuyo conteo es > 0; `weight` es su porción (0..1) del total de causas incluidas.
     *
     * @return list<array{key: string, label: string, count: int, weight: float}>
     */
    private function causes(): array
    {
        $raw = [
            ['key' => 'late_routes', 'label' => 'Rutas atrasadas (ETA pasó la ventana)', 'count' => $this->lateRoutesCount()],
            ['key' => 'missing_confirmations', 'label' => 'Confirmaciones de entrega faltantes', 'count' => $this->missingConfirmationsCount()],
            ['key' => 'expired_promises', 'label' => 'Reservas de cupo vencidas', 'count' => $this->expiredPromisesCount()],
            ['key' => 'dock_backlog', 'label' => 'Andén saturado (recepciones sin conciliar)', 'count' => $this->dockBacklogCount()],
        ];

        $included = array_values(array_filter($raw, static fn (array $c): bool => $c['count'] > 0));
        $total = array_sum(array_column($included, 'count'));

        usort($included, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_map(static function (array $c) use ($total): array {
            return [
                'key' => $c['key'],
                'label' => $c['label'],
                'count' => $c['count'],
                'weight' => $total > 0 ? round($c['count'] / $total, 4) : 0.0,
            ];
        }, $included);
    }

    /**
     * Titular legible según cuántos envíos corren riesgo de perder su ventana.
     */
    private function headline(int $atRisk): string
    {
        if ($atRisk <= 0) {
            return 'Todos los envíos activos van en plan.';
        }

        $noun = $atRisk === 1 ? 'envío podría perder su ventana de entrega' : 'envíos podrían perder su ventana de entrega';

        return "{$atRisk} {$noun}. El resto de la red va en plan.";
    }

    /**
     * Envíos activos (no terminales). Cero si la tabla no existe.
     */
    private function activeShipments(): int
    {
        try {
            return $this->remember('brief:active_shipments', fn (): int => (int) DB::table('shipments')
                ->whereNotIn('status', CockpitStates::SHIPMENT_TERMINAL)
                ->count());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Conteo de entregas en riesgo (predicado único del trait). Cero si falta la fuente.
     */
    private function atRiskCount(): int
    {
        try {
            return $this->remember('brief:at_risk', fn (): int => $this->atRiskDeliveriesQuery()->count());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Rutas tarde: paradas sin cerrar (settled_at IS NULL) cuya ETA ya pasó. Conteo de envíos distintos.
     */
    private function lateRoutesCount(): int
    {
        try {
            return $this->remember('brief:late_routes', fn (): int => (int) DB::table('execution_driver_route_stops')
                ->whereNull('settled_at')
                ->whereNotNull('eta')
                ->where('eta', '<', now())
                ->distinct()
                ->count('shipment_id'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Confirmaciones faltantes: entregas cargadas/en ruta, sin prueba de entrega, con la ventana vencida.
     */
    private function missingConfirmationsCount(): int
    {
        try {
            return $this->remember('brief:missing_confirmations', fn (): int => $this->atRiskDeliveriesQuery()
                ->whereIn('d.status', ['LOADED', 'IN_TRANSIT'])
                ->whereNull('d.pod_confirmation')
                ->count());
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Reservas de promesa vencidas: expiradas, o reservadas con la ventana ya cumplida.
     */
    private function expiredPromisesCount(): int
    {
        try {
            return $this->remember('brief:expired_promises', fn (): int => (int) DB::table('promises')
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

    /**
     * Backlog de dársena: recepciones en nodo todavía sin conciliar (reconciled_at IS NULL).
     */
    private function dockBacklogCount(): int
    {
        try {
            return $this->remember('brief:dock_backlog', fn (): int => (int) DB::table('execution_node_receptions')
                ->whereNull('reconciled_at')
                ->count());
        } catch (\Throwable) {
            return 0;
        }
    }
}
