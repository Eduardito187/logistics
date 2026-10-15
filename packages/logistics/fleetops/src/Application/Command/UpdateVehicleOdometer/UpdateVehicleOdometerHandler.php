<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\UpdateVehicleOdometer;

use Logistics\FleetOps\Domain\Contract\VehicleProfileRepository;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Actualiza el odómetro vigente del perfil de un vehículo, lo persiste y publica sus eventos al outbox.
 * Falla si el vehículo no tiene perfil definido.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<UpdateVehicleOdometer>
 */
final readonly class UpdateVehicleOdometerHandler implements CommandHandler
{
    public function __construct(
        private VehicleProfileRepository $profiles,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateVehicleOdometer);

        $profile = $this->profiles->findByVehicle($command->vehicleId);
        if ($profile === null) {
            throw FleetOpsException::because("No existe perfil para el vehículo {$command->vehicleId}.");
        }

        $profile->updateOdometer(Odometer::km($command->km));

        $this->profiles->save($profile);

        $this->recordEvents->record($profile->releaseEvents(), 'fleetops.vehicle_profile');
    }
}
