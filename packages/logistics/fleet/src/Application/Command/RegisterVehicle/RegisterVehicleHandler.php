<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\RegisterVehicle;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\Capacity;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\PlateNumber;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el vehículo, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RegisterVehicle>
 */
final readonly class RegisterVehicleHandler implements CommandHandler
{
    public function __construct(
        private VehicleRepository $vehicles,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterVehicle);

        $documents = array_map(
            static fn (array $doc): DocumentExpiry => DocumentExpiry::of(
                $doc['type'],
                new DateTimeImmutable($doc['expires_on']),
            ),
            $command->documents,
        );

        $vehicle = Vehicle::register(
            $this->vehicles->nextIdentity(),
            PlateNumber::of($command->plate),
            Capacity::of($command->capacityKg, $command->capacityM3),
            $command->homeBaseNodeId,
            array_values($documents),
            VehicleStatus::from($command->status),
        );

        $this->vehicles->save($vehicle);

        $this->recordEvents->record($vehicle->releaseEvents(), 'fleet.vehicle');
    }
}
