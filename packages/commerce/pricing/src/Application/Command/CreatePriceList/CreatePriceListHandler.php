<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Command\CreatePriceList;

use Commerce\Pricing\Domain\Contract\PriceListRepository;
use Commerce\Pricing\Domain\Model\PriceList;
use Commerce\Pricing\Domain\ValueObject\Channel;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye la lista de precios, la persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<CreatePriceList>
 */
final readonly class CreatePriceListHandler implements CommandHandler
{
    public function __construct(
        private PriceListRepository $priceLists,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CreatePriceList);

        $list = PriceList::create(
            $this->priceLists->nextIdentity(),
            $command->name,
            Channel::from($command->channel),
            $command->currency,
        );

        $this->priceLists->save($list);

        $this->recordEvents->record($list->releaseEvents(), 'pricing.price_list');
    }
}
