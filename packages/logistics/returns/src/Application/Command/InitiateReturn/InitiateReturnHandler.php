<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Command\InitiateReturn;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Returns\Domain\Contract\ReturnOrderRepository;
use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Returns\Domain\ValueObject\ReturnKind;
use Logistics\Returns\Domain\ValueObject\ReturnLine;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye la devolución, la persiste y publica sus eventos al outbox (en la transacción del CommandBus).
 *
 * @implements CommandHandler<InitiateReturn>
 */
final readonly class InitiateReturnHandler implements CommandHandler
{
    public function __construct(
        private ReturnOrderRepository $returns,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof InitiateReturn);

        $lines = array_map(
            static fn (array $line): ReturnLine => ReturnLine::of($line['sku'], $line['quantity'], $line['reason']),
            array_values($command->lines),
        );

        $returnOrder = ReturnOrder::initiate(
            $this->returns->nextIdentity(),
            $command->originalReference,
            $command->ownerPartyId,
            $command->warehouseId,
            ReturnKind::from($command->kind),
            $lines,
        );

        $this->returns->save($returnOrder);

        $this->recordEvents->record($returnOrder->releaseEvents(), 'returns.return');
    }
}
