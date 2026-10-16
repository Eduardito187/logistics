<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Command\SuspendSeller;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Sellers\Domain\Contract\SellerRepository;
use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el seller, lo suspende, lo persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * @implements CommandHandler<SuspendSeller>
 */
final readonly class SuspendSellerHandler implements CommandHandler
{
    public function __construct(
        private SellerRepository $sellers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SuspendSeller);

        $seller = $this->sellers->findById(SellerId::fromString($command->sellerId));
        if ($seller === null) {
            throw SellersException::because("No existe el seller {$command->sellerId}.");
        }

        $seller->suspend();

        $this->sellers->save($seller);

        $this->recordEvents->record($seller->releaseEvents(), 'sellers.seller');
    }
}
