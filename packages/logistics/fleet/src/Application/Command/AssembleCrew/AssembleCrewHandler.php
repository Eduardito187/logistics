<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\AssembleCrew;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Contract\CrewRoster;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Contract\ShiftRulesPolicy;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Exception\CrewConflict;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\Service\CrewShiftScheduler;
use Logistics\Fleet\Domain\ValueObject\CrewId;
use Logistics\Fleet\Domain\ValueObject\CrewRetirementReason;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Domain\ValueObject\WorkShift;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Event\DomainEvent;

/**
 * Arma la tripulación, la persiste y publica sus eventos al outbox.
 *
 * Regla del día: **un chofer = un camión por día, y un camión = una tripulación por día**. Por eso armar
 * no suma: REEMPLAZA.
 *
 *  - Si el chofer ya tiene tripulación ese día, la anterior queda sin efecto (con su rastro histórico) y la
 *    nueva ocupa su lugar. Con otro camión, el hecho publicado es «camión cambiado» (anterior y nuevo).
 *  - Volver a pedir exactamente lo que ya tiene (mismo camión, ayudantes y turno) no hace nada: ni otra
 *    tripulación ni otro evento.
 *  - Si el camión ya lo tiene OTRO chofer ese día, se rechaza con CrewConflict (409) nombrando la placa y
 *    el chofer; con `replace` esa otra tripulación también queda sin efecto.
 *  - Los ayudantes de la tripulación anterior se conservan si el pedido no trae ayudantes; si trae, mandan
 *    los del pedido. Un ayudante que estaba en otra tripulación del día sale de aquella.
 *
 * Si queda un turno (el del pedido o el heredado), se valida contra las reglas de turnos y descansos
 * DESPUÉS de dejar sin efecto lo reemplazado, para que la tripulación anterior no choque consigo misma.
 * Tampoco se arma si el vehículo está NO APTO para esa fecha (FleetException → 422). Un vehículo que no
 * está registrado en flota no se bloquea acá (compatibilidad: la existencia no se validaba antes).
 *
 * Los cambios y el append al outbox corren dentro de la misma transacción, que abre el decorador
 * transaccional del CommandBus (Infrastructure): si algo falla, la tripulación anterior sigue vigente. El
 * bloqueo del día (CrewRoster::lockDay) atiende de a uno los pedidos simultáneos de una misma fecha.
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
        private VehicleRepository $vehicles,
        private CrewRoster $roster,
        private DriverRepository $drivers,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AssembleCrew);

        $assembledFor = new DateTimeImmutable($command->assembledFor);
        $driverId = DriverId::fromString($command->driverId);
        $vehicleId = VehicleId::fromString($command->vehicleId);

        $this->roster->lockDay($assembledFor);

        // Regla de negocio (#27): un vehículo no apto no se asigna hasta que un supervisor lo habilite.
        $vehicle = $this->vehicles->findById($vehicleId);
        $vehicle?->ensureFitToDispatchOn($assembledFor);

        $edited = $this->editedCrew($command);
        $current = $this->crews->findForDriverOn($driverId, $assembledFor);
        $previous = $current ?? $edited;

        $helperIds = array_values($command->helperIds ?? $previous?->helperIds() ?? []);
        $shift = $this->resolveShift($command) ?? $previous?->shift();

        if ($this->alreadyAssembled($current, $edited, $vehicleId, $helperIds, $shift)) {
            return;
        }

        $occupant = $this->occupantToDisplace($command, $vehicle, $edited);

        $crew = $previous instanceof Crew
            ? Crew::assembleReplacing($previous, $this->crews->nextIdentity(), $driverId, $helperIds, $vehicleId, $assembledFor, $shift)
            : Crew::assemble($this->crews->nextIdentity(), $driverId, $helperIds, $vehicleId, $assembledFor, $shift);

        $events = $this->retireSuperseded([$current, $edited, $occupant], $crew);
        $this->roster->releaseHelpersOn($helperIds, $assembledFor);

        if ($shift instanceof WorkShift) {
            $this->scheduler->ensureAssignable($shift, $this->shiftRules->rules(), [$command->driverId, ...$helperIds]);
        }

        $this->crews->save($crew);

        $this->recordEvents->record([...$events, ...$crew->releaseEvents()], 'fleet.crew');
    }

    /**
     * Tripulación que se está editando (PUT), o null si el pedido es un armado común. Editar una que no
     * existe es un error del pedido.
     */
    private function editedCrew(AssembleCrew $command): ?Crew
    {
        if ($command->replacesCrewId === null) {
            return null;
        }

        return $this->crews->findById(CrewId::fromString($command->replacesCrewId))
            ?? throw FleetException::because('La tripulación no existe.');
    }

    /**
     * ¿El chofer ya tiene exactamente lo pedido? Entonces repetir el pedido no cambia nada (idempotente).
     *
     * @param list<string> $helperIds
     */
    private function alreadyAssembled(?Crew $current, ?Crew $edited, VehicleId $vehicleId, array $helperIds, ?WorkShift $shift): bool
    {
        if (!$current instanceof Crew || !$current->holdsSameAssignment($vehicleId, $helperIds, $shift)) {
            return false;
        }

        return !$edited instanceof Crew || $edited->id()->equals($current->id());
    }

    /**
     * Tripulación de OTRO chofer que ya ocupa el camión ese día y que este armado deja sin efecto, o null
     * si el camión está libre (o ya es de este chofer). Sin confirmación de reemplazo se rechaza, salvo que
     * sea justamente la tripulación que se está editando.
     */
    private function occupantToDisplace(AssembleCrew $command, ?Vehicle $vehicle, ?Crew $edited): ?Crew
    {
        $assembledFor = new DateTimeImmutable($command->assembledFor);
        $occupant = $this->roster->findForVehicleOn(VehicleId::fromString($command->vehicleId), $assembledFor);

        if (!$occupant instanceof Crew || $occupant->driverId()->toString() === $command->driverId) {
            return null;
        }

        $isTheEditedOne = $edited instanceof Crew && $edited->id()->equals($occupant->id());
        if (!$command->replace && !$isTheEditedOne) {
            throw CrewConflict::vehicleTaken(
                $vehicle?->plate()->value ?? 'solicitado',
                $this->drivers->findById($occupant->driverId())?->name() ?? 'otro chofer',
                $assembledFor->format('d/m/Y'),
            );
        }

        return $occupant;
    }

    /**
     * Deja sin efecto las tripulaciones que la nueva reemplaza (cada una una sola vez) y devuelve los
     * eventos que eso produce. La del mismo chofer queda «reemplazada»; la de otro chofer, «desplazada».
     *
     * @param  list<Crew|null>   $superseded
     * @return list<DomainEvent>
     */
    private function retireSuperseded(array $superseded, Crew $successor): array
    {
        $events = [];
        $seen = [];

        foreach ($superseded as $old) {
            if (!$old instanceof Crew || isset($seen[$old->id()->toString()])) {
                continue;
            }
            $seen[$old->id()->toString()] = true;

            $reason = $old->driverId()->equals($successor->driverId())
                ? CrewRetirementReason::Replaced
                : CrewRetirementReason::Displaced;
            if ($reason === CrewRetirementReason::Displaced) {
                $old->disband($reason);
            }

            $this->roster->retire($old, $reason, $successor);
            $events = [...$events, ...$old->releaseEvents()];
        }

        return $events;
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
