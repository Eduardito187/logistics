<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Logistics\Planning\Application\ReadModel\AutoPlanRunView;
use Logistics\Planning\Domain\Contract\AutoPlanRunRepository;
use Logistics\Planning\Domain\Model\AutoPlanRun;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Infrastructure\Realtime\PlanCityReader;
use Logistics\Planning\Infrastructure\Realtime\PlanningRealtimeBroadcaster;
use Throwable;

/**
 * Decorador del repositorio de corridas del auto-planificador que avisa en tiempo real
 * (`planning.run.updated`) cada vez que se guarda una corrida, o sea en cada cambio de estado
 * (`queued` → `processing` → `completed` | `failed`).
 *
 * La corrida es del día entero: se avisa a `planning.all` y a todas las ciudades. El aviso nunca altera el
 * guardado: sin transporte de tiempo real no hace nada y cualquier falla se traga.
 */
final readonly class BroadcastingAutoPlanRunRepository implements AutoPlanRunRepository
{
    public function __construct(
        private AutoPlanRunRepository $inner,
        private PlanningRealtimeBroadcaster $realtime,
        private PlanCityReader $cities,
    ) {}

    public function nextIdentity(): AutoPlanRunId
    {
        return $this->inner->nextIdentity();
    }

    public function save(AutoPlanRun $run): void
    {
        $this->inner->save($run);

        try {
            if ($this->realtime->enabled()) {
                $this->realtime->runChanged(AutoPlanRunView::fromDomain($run), $this->cities->allCities());
            }
        } catch (Throwable) {
            // La corrida ya quedó guardada: la señal es best-effort.
        }
    }

    public function findById(AutoPlanRunId $id): ?AutoPlanRun
    {
        return $this->inner->findById($id);
    }

    public function activeFor(PlanDate $date, PlanStrategy $strategy): ?AutoPlanRun
    {
        return $this->inner->activeFor($date, $strategy);
    }

    public function latestFor(PlanDate $date, PlanStrategy $strategy): ?AutoPlanRun
    {
        return $this->inner->latestFor($date, $strategy);
    }
}
