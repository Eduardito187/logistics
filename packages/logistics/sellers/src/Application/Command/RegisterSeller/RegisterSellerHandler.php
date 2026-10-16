<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Command\RegisterSeller;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Sellers\Domain\Contract\SellerRepository;
use Logistics\Sellers\Domain\Model\Seller;
use Logistics\Sellers\Domain\ValueObject\ContactInfo;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye el seller, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RegisterSeller>
 */
final readonly class RegisterSellerHandler implements CommandHandler
{
    public function __construct(
        private SellerRepository $sellers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RegisterSeller);

        $seller = Seller::register(
            $this->sellers->nextIdentity(),
            $command->legalName,
            $command->taxId,
            ContactInfo::of($command->contactName, $command->email, $command->phone),
        );

        $this->sellers->save($seller);

        $this->recordEvents->record($seller->releaseEvents(), 'sellers.seller');
    }
}
