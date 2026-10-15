<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\CrewId;
use Logistics\Fleet\Domain\ValueObject\WorkShift;

/**
 * Puerto de persistencia de tripulaciones. La implementación vive en Infrastructure.
 */
interface CrewRepository
{
    public function nextIdentity(): CrewId;

    public function save(Crew $crew): void;

    public function findById(CrewId $id): ?Crew;

    /**
     * @return list<Crew>
     */
    public function all(): array;

    /**
     * Turnos ya asignados a una persona (como chofer o como ayudante) cuyas tripulaciones caen entre dos
     * fechas de armado. Solo tripulaciones con turno fijado; sirve para validar descanso y solapamiento.
     *
     * @return list<WorkShift>
     */
    public function workShiftsForMemberBetween(string $memberId, DateTimeImmutable $from, DateTimeImmutable $to): array;
}
