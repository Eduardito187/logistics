<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\ChangeVehicleStatus;

use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el vehículo, le cambia el estado y persiste. Falla si el vehículo no existe.
 *
 * TODO(outbox): publicar los eventos liberados cuando se integre el package outbox.
 *
 * @implements CommandHandler<ChangeVehicleStatus>
 */
final readonly class ChangeVehicleStatusHandler implements CommandHandler
{
    public function __construct(
        private VehicleRepository $vehicles,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ChangeVehicleStatus);

        $vehicle = $this->vehicles->findById(VehicleId::fromString($command->vehicleId));
        if ($vehicle === null) {
            throw FleetException::because("No existe el vehículo {$command->vehicleId}.");
        }

        $vehicle->changeStatus(VehicleStatus::from($command->status));

        $this->vehicles->save($vehicle);
        $vehicle->releaseEvents();
    }
}
