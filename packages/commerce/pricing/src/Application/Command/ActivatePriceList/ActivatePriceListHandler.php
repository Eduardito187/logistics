<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Command\ActivatePriceList;

use Commerce\Pricing\Domain\Contract\PriceListRepository;
use Commerce\Pricing\Domain\Exception\PricingException;
use Commerce\Pricing\Domain\ValueObject\PriceListId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la lista, la activa, la persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * @implements CommandHandler<ActivatePriceList>
 */
final readonly class ActivatePriceListHandler implements CommandHandler
{
    public function __construct(
        private PriceListRepository $priceLists,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ActivatePriceList);

        $list = $this->priceLists->findById(PriceListId::fromString($command->priceListId));
        if ($list === null) {
            throw PricingException::because("No existe la lista de precios {$command->priceListId}.");
        }

        $list->activate();

        $this->priceLists->save($list);

        $this->recordEvents->record($list->releaseEvents(), 'pricing.price_list');
    }
}
