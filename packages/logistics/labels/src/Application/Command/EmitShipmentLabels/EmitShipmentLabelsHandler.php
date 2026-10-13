<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\EmitShipmentLabels;

use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Contract\LabelRenderer;
use Logistics\Labels\Domain\Contract\LabelSigner;
use Logistics\Labels\Domain\Model\EmittedLabel;
use Logistics\Labels\Domain\Service\LabelBatchFactory;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Emite el lote de etiquetas de un pedido: arma los payloads, los firma, renderiza el ZPL y los
 * persiste. El repositorio corta con DuplicateLabelException si algún `id` ya fue emitido.
 *
 * Todas las etiquetas del lote comparten la misma marca de tiempo de emisión (un reloj, una emisión).
 *
 * @implements CommandHandler<EmitShipmentLabels>
 */
final readonly class EmitShipmentLabelsHandler implements CommandHandler
{
    public function __construct(
        private LabelBatchFactory $factory,
        private LabelSigner $signer,
        private LabelRenderer $renderer,
        private EmittedLabelRepository $repository,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof EmitShipmentLabels);

        $issuedAt = $this->clock->now()->getTimestamp();

        $lines = array_map(
            static fn (OrderLineInput $line): array => [
                'sku' => $line->sku,
                'quantity' => $line->quantity,
                'piecesPerUnit' => $line->piecesPerUnit,
            ],
            array_values($command->lines),
        );

        $payloads = $this->factory->build(
            keyId: $this->signer->activeKeyId(),
            orderNumber: $command->orderNumber,
            origin: $command->origin,
            destination: $command->destination,
            issuedAt: $issuedAt,
            lines: $lines,
        );

        foreach ($payloads as $payload) {
            $signed = $this->signer->sign($payload);
            $zpl = $this->renderer->render($signed);

            $this->repository->add(EmittedLabel::of($command->orderNumber, $signed, $zpl, $issuedAt));
        }
    }
}
