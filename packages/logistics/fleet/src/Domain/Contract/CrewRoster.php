<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\CrewRetirementReason;
use Logistics\Fleet\Domain\ValueObject\VehicleId;

/**
 * Puerto de la planilla del día: las operaciones que sostienen la regla «un chofer = un camión por día y
 * un camión = una tripulación por día». La implementación vive en Infrastructure.
 */
interface CrewRoster
{
    /**
     * Serializa los armados de una misma fecha: dos pedidos simultáneos para ese día se atienden de a uno.
     * El bloqueo dura hasta el fin de la transacción en curso.
     */
    public function lockDay(DateTimeImmutable $date): void;

    /**
     * Tripulación vigente de un camión en una fecha, o null si ese día está libre.
     */
    public function findForVehicleOn(VehicleId $vehicleId, DateTimeImmutable $date): ?Crew;

    /**
     * Saca a esos ayudantes de las tripulaciones vigentes de la fecha: un ayudante va en una sola por día.
     *
     * @param list<string> $helperIds
     */
    public function releaseHelpersOn(array $helperIds, DateTimeImmutable $date): void;

    /**
     * Deja sin efecto una tripulación: sale de las vigentes y queda su rastro histórico con el motivo y,
     * si la hay, la tripulación que la reemplaza.
     */
    public function retire(Crew $crew, CrewRetirementReason $reason, ?Crew $successor = null): void;
}
