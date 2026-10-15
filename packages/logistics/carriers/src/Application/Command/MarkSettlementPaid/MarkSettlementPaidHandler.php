<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\MarkSettlementPaid;

use Logistics\Carriers\Domain\Contract\SettlementRepository;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\SettlementId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la liquidación, la marca pagada, la persiste y publica sus eventos al outbox. Falla si no existe.
 *
 * @implements CommandHandler<MarkSettlementPaid>
 */
final readonly class MarkSettlementPaidHandler implements CommandHandler
{
    public function __construct(
        private SettlementRepository $settlements,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof MarkSettlementPaid);

        $settlement = $this->settlements->findById(SettlementId::fromString($command->settlementId));
        if ($settlement === null) {
            throw CarriersException::because("No existe la liquidación {$command->settlementId}.");
        }

        $settlement->markPaid($command->paymentReference);

        $this->settlements->save($settlement);

        $this->recordEvents->record($settlement->releaseEvents(), 'carriers.settlement');
    }
}
