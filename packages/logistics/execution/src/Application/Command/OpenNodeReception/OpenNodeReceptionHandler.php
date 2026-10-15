<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\OpenNodeReception;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\NodeReceptionRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\NodeReception;
use Logistics\Execution\Domain\ValueObject\Corridor;
use Logistics\Execution\Domain\ValueObject\NodeKind;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Abre la recepción en nodo y graba NodeReceptionOpened.
 *
 * Idempotente por el par envío+destino: si ya existe una recepción, no abre otra ni re-emite el evento.
 *
 * @implements CommandHandler<OpenNodeReception>
 */
final class OpenNodeReceptionHandler implements CommandHandler
{
    public function __construct(
        private readonly NodeReceptionRepository $receptions,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof OpenNodeReception) {
            throw ExecutionException::because('OpenNodeReceptionHandler solo maneja OpenNodeReception');
        }

        $existing = $this->receptions->findByShipmentAndDestination(
            $command->shipmentId,
            $command->destinationNodeId,
        );
        if ($existing !== null) {
            return;
        }

        $corridor = Corridor::between(
            NodeKind::fromCode($command->originNodeKind),
            NodeKind::fromCode($command->destinationNodeKind),
        );

        $reception = NodeReception::open(
            $this->receptions->nextIdentity(),
            $command->shipmentId,
            $command->originNodeId,
            $command->destinationNodeId,
            $corridor,
            array_values($command->expectedUnitIds),
            new DateTimeImmutable($command->occurredAt),
        );

        $this->receptions->save($reception);

        $this->recordEvents->record($reception->releaseEvents(), 'execution.node_reception');
    }
}
