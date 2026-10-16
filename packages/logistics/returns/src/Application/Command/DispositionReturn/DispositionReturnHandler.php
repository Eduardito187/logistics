<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\Command\DispositionReturn;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Returns\Domain\Contract\ReturnOrderRepository;
use Logistics\Returns\Domain\Exception\ReturnsException;
use Logistics\Returns\Domain\ValueObject\Disposition;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Decide la disposición de la devolución (Received → Dispositioned) y publica el evento al outbox. Cuando
 * la disposición es `restock`, el evento `returns.return_dispositioned` lleva las líneas para que el
 * orquestador dispare `ReceiveStock` en inventory (enganche por outbox, sin dependencia composer).
 *
 * @implements CommandHandler<DispositionReturn>
 */
final readonly class DispositionReturnHandler implements CommandHandler
{
    public function __construct(
        private ReturnOrderRepository $returns,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof DispositionReturn);

        $returnOrder = $this->returns->findById(ReturnId::fromString($command->returnId))
            ?? throw ReturnsException::because("No existe la devolución {$command->returnId}.");

        $returnOrder->dispose(Disposition::from($command->disposition));

        $this->returns->save($returnOrder);

        $this->recordEvents->record($returnOrder->releaseEvents(), 'returns.return');
    }
}
