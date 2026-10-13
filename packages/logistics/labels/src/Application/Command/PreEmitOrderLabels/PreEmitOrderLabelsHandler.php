<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\PreEmitOrderLabels;

use Logistics\Labels\Application\Service\LabelBatchEmitter;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Pre-emite las etiquetas de un pedido al planificar.
 *
 * Idempotente por diseño: si el pedido ya tiene etiquetas vigentes, no reemite (el relay del outbox
 * puede reentregar el evento planning.plan_published sin que eso genere etiquetas duplicadas). La
 * reimpresión real —que revoca y vuelve a emitir— es otro caso de uso (ReprintOrderLabels).
 *
 * @implements CommandHandler<PreEmitOrderLabels>
 */
final readonly class PreEmitOrderLabelsHandler implements CommandHandler
{
    public function __construct(
        private LabelBatchEmitter $emitter,
        private EmittedLabelRepository $repository,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof PreEmitOrderLabels);

        // Ya pre-emitido (o emitido por la app): nada que hacer. Garantiza idempotencia ante reentregas.
        if ($this->repository->activeForOrder($command->orderNumber) !== []) {
            return;
        }

        $this->emitter->emit(
            orderNumber: $command->orderNumber,
            origin: $command->origin,
            destination: $command->destination,
            lines: $command->lines,
            issuedAt: $this->clock->now()->getTimestamp(),
            preEmitted: true,
        );
    }
}
