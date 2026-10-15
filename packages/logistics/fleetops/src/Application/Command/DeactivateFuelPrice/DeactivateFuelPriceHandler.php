<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\DeactivateFuelPrice;

use Logistics\FleetOps\Domain\Contract\FuelPriceRepository;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\FuelPriceId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga un precio, lo desactiva (idempotente), lo persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * @implements CommandHandler<DeactivateFuelPrice>
 */
final readonly class DeactivateFuelPriceHandler implements CommandHandler
{
    public function __construct(
        private FuelPriceRepository $prices,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DeactivateFuelPrice);

        $price = $this->prices->findById(FuelPriceId::fromString($command->id));
        if ($price === null) {
            throw FleetOpsException::because("No existe el precio de combustible {$command->id}.");
        }

        $price->deactivate();

        $this->prices->save($price);

        $this->recordEvents->record($price->releaseEvents(), 'fleetops.fuel_price');
    }
}
