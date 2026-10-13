<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\Service\CrewShiftScheduler;
use Logistics\Fleet\Domain\ValueObject\CrewId;
use Logistics\Fleet\Domain\ValueObject\ShiftRules;
use Logistics\Fleet\Domain\ValueObject\WorkShift;

/**
 * Repositorio de tripulaciones en memoria: solo responde los turnos por persona que se le configuren.
 *
 * @param array<string, list<WorkShift>> $shiftsByMember
 */
function fakeCrewRepository(array $shiftsByMember): CrewRepository
{
    return new class($shiftsByMember) implements CrewRepository
    {
        /** @param array<string, list<WorkShift>> $shiftsByMember */
        public function __construct(private array $shiftsByMember) {}

        public function nextIdentity(): CrewId
        {
            return CrewId::generate();
        }

        public function save(Crew $crew): void {}

        public function findById(CrewId $id): ?Crew
        {
            return null;
        }

        public function all(): array
        {
            return [];
        }

        public function workShiftsForMemberBetween(string $memberId, DateTimeImmutable $from, DateTimeImmutable $to): array
        {
            return $this->shiftsByMember[$memberId] ?? [];
        }
    };
}

function rules(): ShiftRules
{
    return ShiftRules::fromHours(10, 5, 22, 10);
}

it('acepta la asignación cuando nadie tiene turnos previos', function (): void {
    $scheduler = new CrewShiftScheduler(fakeCrewRepository([]));
    $shift = WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 14:00'));

    $scheduler->ensureAssignable($shift, rules(), ['driver-1', 'helper-1']);
})->throwsNoExceptions();

it('rechaza cuando el chofer ya tiene un turno que viola el descanso', function (): void {
    $previous = WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 14:00'));
    $scheduler = new CrewShiftScheduler(fakeCrewRepository(['driver-1' => [$previous]]));
    // Nuevo turno el mismo día de noche: solo 6 h de descanso respecto del anterior.
    $shift = WorkShift::between(new DateTimeImmutable('2026-10-14 20:00'), new DateTimeImmutable('2026-10-14 21:30'));

    expect(fn () => $scheduler->ensureAssignable($shift, rules(), ['driver-1']))
        ->toThrow(FleetException::class, 'descanso mínimo');
});

it('rechaza cuando un ayudante ya tiene un turno que se solapa', function (): void {
    $previous = WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 14:00'));
    $scheduler = new CrewShiftScheduler(fakeCrewRepository(['helper-9' => [$previous]]));
    $shift = WorkShift::between(new DateTimeImmutable('2026-10-14 10:00'), new DateTimeImmutable('2026-10-14 18:00'));

    expect(fn () => $scheduler->ensureAssignable($shift, rules(), ['driver-1', 'helper-9']))
        ->toThrow(FleetException::class, 'se solapa');
});

it('valida la ventana y la jornada antes de mirar turnos previos', function (): void {
    $scheduler = new CrewShiftScheduler(fakeCrewRepository([]));
    // Turno fuera de la ventana (termina a las 23:00).
    $shift = WorkShift::between(new DateTimeImmutable('2026-10-14 15:00'), new DateTimeImmutable('2026-10-14 23:00'));

    expect(fn () => $scheduler->ensureAssignable($shift, rules(), ['driver-1']))
        ->toThrow(FleetException::class, 'después de la ventana');
});
