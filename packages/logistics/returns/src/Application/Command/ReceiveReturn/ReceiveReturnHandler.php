<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Command\ReceiveReturn;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Returns\Domain\Contract\ReturnOrderRepository;
use Logistics\Returns\Domain\Exception\ReturnsException;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Marca la devolución como recibida (Initiated → Received) y publica el evento al outbox.
 *
 * @implements CommandHandler<ReceiveReturn>
 */
final readonly class ReceiveReturnHandler implements CommandHandler
{
    public function __construct(
        private ReturnOrderRepository $returns,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReceiveReturn);

        $returnOrder = $this->returns->findById(ReturnId::fromString($command->returnId))
            ?? throw ReturnsException::because("No existe la devolución {$command->returnId}.");

        $returnOrder->receive();

        $this->returns->save($returnOrder);

        $this->recordEvents->record($returnOrder->releaseEvents(), 'returns.return');
    }
}
