<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\Model\AutoPlanRun;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;

/**
 * Puerta de persistencia de las corridas del auto-planificador. El dominio la define; la implementación
 * Eloquent vive en Infrastructure.
 */
interface AutoPlanRunRepository
{
    /**
     * Reserva una identidad nueva (UUID v7 del dominio).
     */
    public function nextIdentity(): AutoPlanRunId;

    public function save(AutoPlanRun $run): void;

    public function findById(AutoPlanRunId $id): ?AutoPlanRun;

    /**
     * Corrida ACTIVA o ya completada para un día+modo (la que bloquea una nueva, por idempotencia), o
     * null si no hay ninguna o la última falló. Permite re-disparar solo cuando corresponde.
     */
    public function activeFor(PlanDate $date, PlanStrategy $strategy): ?AutoPlanRun;

    /**
     * Última corrida registrada para un día+modo (cualquier estado), para consultar su resultado.
     */
    public function latestFor(PlanDate $date, PlanStrategy $strategy): ?AutoPlanRun;
}
