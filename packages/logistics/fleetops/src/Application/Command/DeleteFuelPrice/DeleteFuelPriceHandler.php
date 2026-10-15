<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\DeleteFuelPrice;

use Logistics\FleetOps\Domain\Contract\FuelPriceRepository;
use Logistics\FleetOps\Domain\Event\FuelPriceDeleted;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\FuelPriceId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Elimina un precio de combustible del registro y publica el evento correspondiente al outbox. Falla si
 * no existe.
 *
 * El delete del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<DeleteFuelPrice>
 */
final readonly class DeleteFuelPriceHandler implements CommandHandler
{
    public function __construct(
        private FuelPriceRepository $prices,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DeleteFuelPrice);

        $id = FuelPriceId::fromString($command->id);

        $price = $this->prices->findById($id);
        if ($price === null) {
            throw FleetOpsException::because("No existe el precio de combustible {$command->id}.");
        }

        $this->prices->delete($id);

        $this->recordEvents->record([new FuelPriceDeleted($id->toString())], 'fleetops.fuel_price');
    }
}
