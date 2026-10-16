<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Command\ActivateSeller;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Sellers\Domain\Contract\SellerRepository;
use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el seller, lo activa, lo persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * @implements CommandHandler<ActivateSeller>
 */
final readonly class ActivateSellerHandler implements CommandHandler
{
    public function __construct(
        private SellerRepository $sellers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ActivateSeller);

        $seller = $this->sellers->findById(SellerId::fromString($command->sellerId));
        if ($seller === null) {
            throw SellersException::because("No existe el seller {$command->sellerId}.");
        }

        $seller->activate();

        $this->sellers->save($seller);

        $this->recordEvents->record($seller->releaseEvents(), 'sellers.seller');
    }
}
