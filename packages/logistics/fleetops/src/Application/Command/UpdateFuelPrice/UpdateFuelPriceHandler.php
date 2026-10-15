<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\UpdateFuelPrice;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\FuelPriceRepository;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\FuelPriceId;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga un precio, lo edita (precio/moneda/vigencia/fuente y tipo si llega), lo persiste y publica sus
 * eventos al outbox. Falla si no existe.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<UpdateFuelPrice>
 */
final readonly class UpdateFuelPriceHandler implements CommandHandler
{
    public function __construct(
        private FuelPriceRepository $prices,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpdateFuelPrice);

        $price = $this->prices->findById(FuelPriceId::fromString($command->id));
        if ($price === null) {
            throw FleetOpsException::because("No existe el precio de combustible {$command->id}.");
        }

        if ($command->fuelType !== null) {
            $price->changeFuelType(FuelType::from($command->fuelType));
        }

        $price->update(
            Money::of($command->priceCents, $command->currency),
            new DateTimeImmutable($command->effectiveFrom),
            $command->source,
        );

        $this->prices->save($price);

        $this->recordEvents->record($price->releaseEvents(), 'fleetops.fuel_price');
    }
}
