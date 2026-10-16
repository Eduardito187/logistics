<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Command\SetSettlementTerms;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Sellers\Domain\Contract\SellerRepository;
use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\Money;
use Logistics\Sellers\Domain\ValueObject\PaymentCycle;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Domain\ValueObject\SettlementTerms;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el seller, le pacta los términos de liquidación, lo persiste y publica sus eventos al outbox.
 *
 * @implements CommandHandler<SetSettlementTerms>
 */
final readonly class SetSettlementTermsHandler implements CommandHandler
{
    public function __construct(
        private SellerRepository $sellers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SetSettlementTerms);

        $seller = $this->sellers->findById(SellerId::fromString($command->sellerId));
        if ($seller === null) {
            throw SellersException::because("No existe el seller {$command->sellerId}.");
        }

        $seller->changeSettlementTerms(SettlementTerms::of(
            $command->commissionBasisPoints,
            Money::of($command->fixedFeeCents, $command->currency),
            PaymentCycle::from($command->paymentCycle),
        ));

        $this->sellers->save($seller);

        $this->recordEvents->record($seller->releaseEvents(), 'sellers.seller');
    }
}
