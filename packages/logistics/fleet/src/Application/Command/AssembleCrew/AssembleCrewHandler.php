<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\AssembleCrew;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Contract\ShiftRulesPolicy;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\Service\CrewShiftScheduler;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Domain\ValueObject\WorkShift;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Arma la tripulación, la persiste y publica sus eventos al outbox.
 *
 * Si el comando trae turno (horas de salida y regreso), valida la asignación contra las reglas de turnos
 * y descansos antes de armar: ventana horaria, jornada máxima, y que ni el chofer ni los ayudantes
 * tengan otro turno que se solape o viole el descanso mínimo. Si no cumple, se lanza FleetException y no
 * se arma nada.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<AssembleCrew>
 */
final readonly class AssembleCrewHandler implements CommandHandler
{
    public function __construct(
        private CrewRepository $crews,
        private RecordDomainEvents $recordEvents,
        private ShiftRulesPolicy $shiftRules,
        private CrewShiftScheduler $scheduler,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AssembleCrew);

        $helperIds = array_values($command->helperIds);
        $shift = $this->resolveShift($command);

        if ($shift instanceof WorkShift) {
            $memberIds = [$command->driverId, ...$helperIds];
            $this->scheduler->ensureAssignable($shift, $this->shiftRules->rules(), $memberIds);
        }

        $crew = Crew::assemble(
            $this->crews->nextIdentity(),
            DriverId::fromString($command->driverId),
            $helperIds,
            VehicleId::fromString($command->vehicleId),
            new DateTimeImmutable($command->assembledFor),
            $shift,
        );

        $this->crews->save($crew);

        $this->recordEvents->record($crew->releaseEvents(), 'fleet.crew');
    }

    /**
     * Construye el turno cuando el comando trae tanto el inicio como el fin; null en otro caso.
     */
    private function resolveShift(AssembleCrew $command): ?WorkShift
    {
        if ($command->shiftStartsAt === null || $command->shiftEndsAt === null) {
            return null;
        }

        return WorkShift::between(
            new DateTimeImmutable($command->shiftStartsAt),
            new DateTimeImmutable($command->shiftEndsAt),
        );
    }
}
