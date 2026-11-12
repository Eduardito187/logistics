<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\ClearVehicleFitness;

use Logistics\Fleet\Domain\Contract\VehicleFitnessLog;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\VehicleFitnessLogEntry;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Habilita un vehículo NO APTO: el dominio valida que estuviera bloqueado y que haya nota de reparación;
 * se persiste el vehículo, se deja constancia en la bitácora de aptitud y se publica
 * `fleet.vehicle_cleared` al outbox, cada paso en la misma transacción (decorador del CommandBus).
 *
 * @implements CommandHandler<ClearVehicleFitness>
 */
final readonly class ClearVehicleFitnessHandler implements CommandHandler
{
    public function __construct(
        private VehicleRepository $vehicles,
        private VehicleFitnessLog $fitnessLog,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ClearVehicleFitness);

        $vehicle = $this->vehicles->findById(VehicleId::fromString($command->vehicleId));
        if ($vehicle === null) {
            throw FleetException::because("No existe el vehículo {$command->vehicleId}.");
        }

        $previous = $vehicle->fitness();
        $vehicle->clearFitness($command->clearedById, $command->clearedByLabel, $command->note);

        $this->vehicles->save($vehicle);

        $this->fitnessLog->append(
            new VehicleFitnessLogEntry(
                vehicleId: $vehicle->id()->toString(),
                action: VehicleFitnessLog::ACTION_CLEARED,
                reason: trim($command->note),
                failedItems: $previous->failedItems,
                inspectionId: $previous->inspectionId,
                actorId: $command->clearedById,
                actorLabel: $command->clearedByLabel,
            ),
            $this->clock->now(),
        );

        $this->recordEvents->record($vehicle->releaseEvents(), 'fleet.vehicle');
    }
}
