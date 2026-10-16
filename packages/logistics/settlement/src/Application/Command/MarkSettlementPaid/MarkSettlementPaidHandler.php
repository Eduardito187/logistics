<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Command\MarkSettlementPaid;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Settlement\Domain\Contract\SellerSettlementRepository;
use Logistics\Settlement\Domain\Exception\SettlementException;
use Logistics\Settlement\Domain\ValueObject\SettlementId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la liquidación, la marca pagada al seller, la persiste y publica sus eventos al outbox. Falla si
 * no existe.
 *
 * @implements CommandHandler<MarkSettlementPaid>
 */
final readonly class MarkSettlementPaidHandler implements CommandHandler
{
    public function __construct(
        private SellerSettlementRepository $settlements,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof MarkSettlementPaid);

        $settlement = $this->settlements->findById(SettlementId::fromString($command->settlementId));
        if ($settlement === null) {
            throw SettlementException::because("No existe la liquidación {$command->settlementId}.");
        }

        $settlement->markPaid($command->paymentReference);

        $this->settlements->save($settlement);

        $this->recordEvents->record($settlement->releaseEvents(), 'settlement.seller');
    }
}
