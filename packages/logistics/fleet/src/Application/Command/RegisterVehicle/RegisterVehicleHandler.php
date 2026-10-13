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
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el vehículo, lo persiste y libera sus eventos.
 *
 * TODO(outbox): cuando se integre el package outbox, los eventos liberados se publican en la misma
 * transacción. Por ahora se liberan y se descartan a propósito (no se depende de outbox todavía).
 *
 * @implements CommandHandler<RegisterVehicle>
 */
final readonly class RegisterVehicleHandler implements CommandHandler
{
    public function __construct(
        private VehicleRepository $vehicles,
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
        $vehicle->releaseEvents();
    }
}
