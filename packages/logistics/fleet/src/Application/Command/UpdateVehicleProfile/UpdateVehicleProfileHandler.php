<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\UpdateVehicleProfile;

use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Domain\ValueObject\VehicleProfile;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el vehículo, le actualiza la ficha técnica y persiste. Falla si el vehículo no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<UpdateVehicleProfile>
 */
final readonly class UpdateVehicleProfileHandler implements CommandHandler
{
    public function __construct(
        private VehicleRepository $vehicles,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateVehicleProfile);

        $vehicle = $this->vehicles->findById(VehicleId::fromString($command->vehicleId));
        if ($vehicle === null) {
            throw FleetException::because("No existe el vehículo {$command->vehicleId}.");
        }

        $vehicle->updateProfile(new VehicleProfile(
            $command->make,
            $command->model,
            $command->modelYear,
            $command->vin,
            $command->engineNumber,
            $command->color,
            $command->photoFront,
            $command->photoBack,
            $command->photoLeft,
            $command->photoRight,
        ));

        $this->vehicles->save($vehicle);

        $this->recordEvents->record($vehicle->releaseEvents(), 'fleet.vehicle');
    }
}
