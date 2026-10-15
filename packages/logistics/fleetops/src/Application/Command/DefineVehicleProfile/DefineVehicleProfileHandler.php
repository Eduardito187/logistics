<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\DefineVehicleProfile;

use Logistics\FleetOps\Domain\Contract\VehicleProfileRepository;
use Logistics\FleetOps\Domain\Model\VehicleProfile;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\VehicleProfileId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Crea o actualiza el perfil técnico de un vehículo (upsert por vehicle_id), lo persiste y publica sus
 * eventos al outbox. Si ya existe perfil para el vehículo, actualiza sus datos técnicos (updateSpecs);
 * si no existe, lo define con un id interno nuevo.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<DefineVehicleProfile>
 */
final readonly class DefineVehicleProfileHandler implements CommandHandler
{
    public function __construct(
        private VehicleProfileRepository $profiles,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DefineVehicleProfile);

        $fuelType = $command->fuelType !== null ? FuelType::from($command->fuelType) : null;

        $profile = $this->profiles->findByVehicle($command->vehicleId);
        if ($profile === null) {
            $profile = VehicleProfile::define(
                VehicleProfileId::generate(),
                $command->vehicleId,
                $command->brand,
                $command->model,
                $command->year,
                $fuelType,
                $command->tankLiters,
                $command->vin,
                $command->color,
            );
        } else {
            $profile->updateSpecs(
                $command->brand,
                $command->model,
                $command->year,
                $fuelType,
                $command->tankLiters,
                $command->vin,
                $command->color,
            );
        }

        $this->profiles->save($profile);

        $this->recordEvents->record($profile->releaseEvents(), 'fleetops.vehicle_profile');
    }
}
