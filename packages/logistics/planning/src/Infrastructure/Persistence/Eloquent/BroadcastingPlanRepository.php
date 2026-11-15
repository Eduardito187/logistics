<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Infrastructure\Realtime\PlanCityReader;
use Logistics\Planning\Infrastructure\Realtime\PlanningRealtimeBroadcaster;
use Throwable;

/**
 * Decorador del repositorio de planes que avisa en tiempo real (`plans.updated`) cada vez que se guarda un
 * plan (crear, sumar, quitar o reordenar paradas, publicar, re-optimizar o recalcular el recorrido) o se
 * borra (ahí el aviso lleva `deleted: true`).
 *
 * El guardado y el borrado son los únicos puntos por donde pasa cualquier cambio de un plan, así que
 * decorarlos cubre todas las vías sin tocar el dominio ni los casos de uso. Las ciudades se leen ANTES y
 * DESPUÉS de guardar: así la ciudad de una parada recién quitada también se entera.
 *
 * El aviso nunca altera el guardado: sin transporte de tiempo real no se hace ninguna lectura extra, y
 * cualquier falla del aviso se traga.
 */
final readonly class BroadcastingPlanRepository implements PlanRepository
{
    public function __construct(
        private PlanRepository $inner,
        private PlanningRealtimeBroadcaster $realtime,
        private PlanCityReader $cities,
    ) {}

    public function nextIdentity(): PlanId
    {
        return $this->inner->nextIdentity();
    }

    public function save(DeliveryPlan $plan): void
    {
        if (!$this->realtime->enabled()) {
            $this->inner->save($plan);

            return;
        }

        $planId = $plan->id()->toString();
        $before = $this->citiesOf($planId, $plan->vehicleId());

        $this->inner->save($plan);

        try {
            $cities = array_values(array_unique([...$before, ...$this->citiesOf($planId, $plan->vehicleId())]));

            $this->realtime->planChanged($plan->date()->toString(), $planId, $cities);
        } catch (Throwable) {
            // El plan ya quedó guardado: la señal es best-effort.
        }
    }

    /**
     * Borra el plan y avisa `plans.updated` con `deleted: true`. Las ciudades se leen ANTES de borrar: una
     * vez borrado ya no quedan paradas de dónde sacarlas.
     */
    public function delete(DeliveryPlan $plan): void
    {
        if (!$this->realtime->enabled()) {
            $this->inner->delete($plan);

            return;
        }

        $planId = $plan->id()->toString();
        $before = $this->citiesOf($planId, $plan->vehicleId());

        $this->inner->delete($plan);

        try {
            $this->realtime->planDeleted($plan->date()->toString(), $planId, $before);
        } catch (Throwable) {
            // El plan ya quedó borrado: la señal es best-effort.
        }
    }

    public function findById(PlanId $id): ?DeliveryPlan
    {
        return $this->inner->findById($id);
    }

    public function findByDate(PlanDate $date): array
    {
        return $this->inner->findByDate($date);
    }

    public function idsForGeometryBackfill(?PlanDate $date, bool $all): array
    {
        return $this->inner->idsForGeometryBackfill($date, $all);
    }

    /**
     * @return list<string>
     */
    private function citiesOf(string $planId, string $vehicleId): array
    {
        try {
            return $this->cities->citiesOf($planId, $vehicleId);
        } catch (Throwable) {
            return [];
        }
    }
}
