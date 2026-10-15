<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\SetFuelPrice;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\FuelPriceRepository;
use Logistics\FleetOps\Domain\Model\FuelPrice;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Fija un precio de combustible, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<SetFuelPrice>
 */
final readonly class SetFuelPriceHandler implements CommandHandler
{
    public function __construct(
        private FuelPriceRepository $prices,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SetFuelPrice);

        $price = FuelPrice::set(
            $this->prices->nextIdentity(),
            FuelType::from($command->fuelType),
            Money::of($command->priceCents, $command->currency),
            new DateTimeImmutable($command->effectiveFrom),
            $command->source,
        );

        $this->prices->save($price);

        $this->recordEvents->record($price->releaseEvents(), 'fleetops.fuel_price');
    }
}
