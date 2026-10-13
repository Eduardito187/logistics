<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RecordRefuel;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\RefuelRepository;
use Logistics\FleetOps\Domain\Model\Refuel;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra una carga de combustible, la persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RecordRefuel>
 */
final readonly class RecordRefuelHandler implements CommandHandler
{
    public function __construct(
        private RefuelRepository $refuels,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordRefuel);

        $refuel = Refuel::record(
            $this->refuels->nextIdentity(),
            $command->vehicleId,
            $command->driverId,
            FuelType::from($command->fuelType),
            $command->liters,
            Money::of($command->pricePerLiterCents, $command->currency),
            $command->odometerKm !== null ? Odometer::km($command->odometerKm) : null,
            $command->station,
            $command->fullTank,
            new DateTimeImmutable($command->refueledAt),
            $command->note,
        );

        $this->refuels->save($refuel);

        $this->recordEvents->record($refuel->releaseEvents(), 'fleetops.refuel');
    }
}
