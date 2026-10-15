<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\EmitShipmentLabels;

use Logistics\Labels\Application\Service\LabelBatchEmitter;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Emite el lote de etiquetas de un pedido pedido por la app: arma los payloads, los firma, renderiza
 * el ZPL, los persiste y publica LabelsEmitted al outbox. El repositorio corta con
 * DuplicateLabelException si algún `id` ya fue emitido.
 *
 * Todas las etiquetas del lote comparten la misma marca de tiempo de emisión (un reloj, una emisión).
 *
 * @implements CommandHandler<EmitShipmentLabels>
 */
final readonly class EmitShipmentLabelsHandler implements CommandHandler
{
    public function __construct(
        private LabelBatchEmitter $emitter,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof EmitShipmentLabels);

        $this->emitter->emit(
            orderNumber: $command->orderNumber,
            origin: $command->origin,
            destination: $command->destination,
            lines: $command->lines,
            issuedAt: $this->clock->now()->getTimestamp(),
            preEmitted: false,
        );
    }
}
