<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;

/**
 * Puerta de persistencia del agregado DeliveryPlan. El dominio la define; la implementación Eloquent
 * vive en Infrastructure. Reconstruye el plan completo (con sus paradas), no filas sueltas.
 */
interface PlanRepository
{
    /**
     * Reserva una identidad nueva. El id lo genera el dominio (UUID v7), no la base.
     */
    public function nextIdentity(): PlanId;

    public function save(DeliveryPlan $plan): void;

    public function findById(PlanId $id): ?DeliveryPlan;

    /**
     * Planes de un día, ordenados por vehículo.
     *
     * @return list<DeliveryPlan>
     */
    public function findByDate(PlanDate $date): array;

    /**
     * Ids de los planes candidatos a (re)calcular su geometría: los que no la tienen o, con `$all`, todos.
     * Filtra por día si se indica. Ordenados por día y vehículo.
     *
     * @return list<PlanId>
     */
    public function idsForGeometryBackfill(?PlanDate $date, bool $all): array;
}
