<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Realtime;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Logistics\Planning\Application\ReadModel\AutoPlanRunView;
use Logistics\Planning\Presentation\Broadcasting\PlanningRunUpdated;
use Logistics\Planning\Presentation\Broadcasting\PlansUpdated;
use Throwable;

/**
 * Emisor de los avisos en tiempo real de Planificación (`plans.updated` y `planning.run.updated`).
 *
 * Lo llaman los decoradores de los repositorios cada vez que se guarda un plan o una corrida, así queda
 * cubierta cualquier vía de escritura (comandos de la app, admin, comandos de consola y el worker) sin que
 * el dominio ni los casos de uso sepan de sockets.
 *
 * Reglas:
 *  - Nada de I/O externo acá: los eventos son `ShouldBroadcast`, o sea que solo se ENCOLAN.
 *  - Después del commit: el aviso sale con `DB::afterCommit` (sin transacción abierta sale de inmediato). Si
 *    la transacción se revierte, el aviso no sale.
 *  - Sin ráfagas: los planes guardados o borrados dentro de una misma transacción se juntan en UN aviso por
 *    día (con `plan_id` si fue un único plan, null si fueron varios), con la unión de sus ciudades.
 *    `deleted` va true solo cuando ese único plan se borró.
 *  - Nunca rompe al llamador: cualquier falla al encolar se traga (la señal es best-effort).
 *  - Con `BROADCAST_CONNECTION` `null` o `log` no hace nada (ni lecturas ni cola): ver {@see enabled()}.
 *
 * Se registra `scoped` (una instancia por request o trabajo): lo pendiente vive en la instancia, nunca en
 * una estática.
 */
final class PlanningRealtimeBroadcaster
{
    /**
     * Planes guardados que todavía no se avisaron, por día.
     *
     * @var array<string, array{plans: array<string, true>, cities: array<string, true>, deleted: array<string, true>}>
     */
    private array $pending = [];

    public function __construct(
        private readonly Dispatcher $events,
        private readonly Config $config,
    ) {}

    /**
     * ¿Hay un transporte de tiempo real configurado? Con `null` o `log` no se emite nada.
     */
    public function enabled(): bool
    {
        $driver = $this->config->get('broadcasting.default');

        return is_string($driver) && !in_array(strtolower(trim($driver)), ['', 'null', 'log'], true);
    }

    /**
     * Se guardó un plan de ese día.
     *
     * @param list<string> $cities códigos de departamento de las ciudades del plan
     */
    public function planChanged(string $planDate, string $planId, array $cities): void
    {
        $this->queuePlan($planDate, $planId, $cities, false);
    }

    /**
     * Se borró un plan de ese día. Las ciudades son las que tenía ANTES de borrarse.
     *
     * @param list<string> $cities códigos de departamento de las ciudades que tenía el plan
     */
    public function planDeleted(string $planDate, string $planId, array $cities): void
    {
        $this->queuePlan($planDate, $planId, $cities, true);
    }

    /**
     * Anota el plan en lo pendiente de su día y programa el aviso para después del commit.
     *
     * @param list<string> $cities
     */
    private function queuePlan(string $planDate, string $planId, array $cities, bool $deleted): void
    {
        if (!$this->enabled()) {
            return;
        }

        try {
            $entry = $this->pending[$planDate] ?? ['plans' => [], 'cities' => [], 'deleted' => []];
            $entry['plans'][$planId] = true;
            foreach ($cities as $city) {
                $entry['cities'][$city] = true;
            }
            if ($deleted) {
                $entry['deleted'][$planId] = true;
            } else {
                // Guardado después de un borrado en la misma transacción: el plan vuelve a existir.
                unset($entry['deleted'][$planId]);
            }
            $this->pending[$planDate] = $entry;

            DB::afterCommit(function (): void {
                $this->flushPlans();
            });
        } catch (Throwable) {
            // La señal es best-effort: nunca altera el guardado del plan.
        }
    }

    /**
     * Se guardó la corrida automática con su estado actual.
     *
     * @param list<string> $cities códigos de departamento de las ciudades a avisar
     */
    public function runChanged(AutoPlanRunView $run, array $cities): void
    {
        if (!$this->enabled()) {
            return;
        }

        try {
            $event = new PlanningRunUpdated($run->toArray(), $cities);

            DB::afterCommit(function () use ($event): void {
                $this->emit($event);
            });
        } catch (Throwable) {
            // La señal es best-effort: nunca altera el guardado de la corrida.
        }
    }

    /**
     * Emite un aviso por día con lo acumulado y vacía lo pendiente. Varias devoluciones de llamada del mismo
     * commit llegan acá: la primera emite y las demás no encuentran nada.
     */
    private function flushPlans(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $planDate => $entry) {
            $planIds = array_keys($entry['plans']);
            $only = count($planIds) === 1 ? (string) $planIds[0] : null;

            // `deleted` solo se afirma cuando el aviso es de UN plan y ese plan se borró; con varios
            // planes va false y la app refresca la lista entera del día.
            $this->emit(new PlansUpdated(
                (string) $planDate,
                $only,
                array_map(strval(...), array_keys($entry['cities'])),
                $only !== null && isset($entry['deleted'][$only]),
            ));
        }
    }

    private function emit(PlansUpdated | PlanningRunUpdated $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable) {
            // Sin cola o sin servidor de sockets la señal se pierde: la app refresca a mano.
        }
    }
}
