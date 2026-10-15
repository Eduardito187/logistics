<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\ReprintOrderLabels;

use Logistics\Labels\Application\Service\LabelBatchEmitter;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Event\LabelsRevoked;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reimprime las etiquetas de un pedido: revoca las vigentes (publica LabelsRevoked) y emite un lote
 * nuevo con `id` nuevos (publica LabelsEmitted vía el emisor). Ambas operaciones corren dentro de la
 * misma transacción del CommandBus, de modo que nunca quede un pedido sin etiquetas válidas.
 *
 * @implements CommandHandler<ReprintOrderLabels>
 */
final readonly class ReprintOrderLabelsHandler implements CommandHandler
{
    public function __construct(
        private LabelBatchEmitter $emitter,
        private EmittedLabelRepository $repository,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ReprintOrderLabels);

        $now = $this->clock->now()->getTimestamp();

        $revoked = $this->repository->revokeForOrder($command->orderNumber, $now, $command->reason);
        if ($revoked !== []) {
            $this->recordEvents->record(
                [new LabelsRevoked($command->orderNumber, $revoked, $command->reason, $now)],
                'labels.order',
            );
        }

        $this->emitter->emit(
            orderNumber: $command->orderNumber,
            origin: $command->origin,
            destination: $command->destination,
            lines: $command->lines,
            issuedAt: $now,
            preEmitted: false,
        );
    }
}
