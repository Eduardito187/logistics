<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Command\ConfirmSettlement;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Settlement\Domain\Contract\SellerSettlementRepository;
use Logistics\Settlement\Domain\Exception\SettlementException;
use Logistics\Settlement\Domain\ValueObject\SettlementId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la liquidación, la confirma, la persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * @implements CommandHandler<ConfirmSettlement>
 */
final readonly class ConfirmSettlementHandler implements CommandHandler
{
    public function __construct(
        private SellerSettlementRepository $settlements,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ConfirmSettlement);

        $settlement = $this->settlements->findById(SettlementId::fromString($command->settlementId));
        if ($settlement === null) {
            throw SettlementException::because("No existe la liquidación {$command->settlementId}.");
        }

        $settlement->confirm();

        $this->settlements->save($settlement);

        $this->recordEvents->record($settlement->releaseEvents(), 'settlement.seller');
    }
}
