<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Service;

use DateTimeImmutable;
use Logistics\Labels\Application\Dto\OrderLineInput;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Labels\Domain\Contract\LabelRenderer;
use Logistics\Labels\Domain\Contract\LabelSigner;
use Logistics\Labels\Domain\Contract\LabelSymbologyProvider;
use Logistics\Labels\Domain\Event\LabelsEmitted;
use Logistics\Labels\Domain\Model\EmittedLabel;
use Logistics\Labels\Domain\Service\LabelBatchFactory;
use Logistics\Outbox\Application\RecordDomainEvents;

/**
 * Colaborador de aplicación que concentra el flujo de emisión de un lote de etiquetas, reutilizado por
 * los tres casos de uso de escritura (emisión por la app, pre-emisión al planificar y reimpresión).
 *
 * Pasos: arma los payloads (uno por bulto), los firma, renderiza su ZPL, los persiste detectando
 * duplicados y publica el evento LabelsEmitted al outbox. Todo corre dentro de la transacción que abre
 * el decorador transaccional del CommandBus; por eso aquí no hay nada del framework.
 */
final readonly class LabelBatchEmitter
{
    public function __construct(
        private LabelBatchFactory $factory,
        private LabelSigner $signer,
        private LabelRenderer $renderer,
        private LabelSymbologyProvider $symbology,
        private EmittedLabelRepository $repository,
        private RecordDomainEvents $recordEvents,
    ) {}

    /**
     * Emite el lote de etiquetas de un pedido y devuelve los `labelId` emitidos, en orden.
     *
     * @param  list<OrderLineInput> $lines      líneas del pedido, en orden (la posición fija el número de línea)
     * @param  bool                 $preEmitted true si la emisión la dispara el planificador (worker), no la app
     * @return list<string>
     */
    public function emit(
        string $orderNumber,
        string $origin,
        string $destination,
        array $lines,
        int $issuedAt,
        bool $preEmitted,
    ): array {
        $keyId = $this->signer->activeKeyId();

        // La simbología (QR, Code128 o ambos) es una regla versionada; se resuelve a la fecha de emisión
        // del lote y se aplica a todas sus etiquetas, para que un mismo pedido imprima homogéneo.
        $symbology = $this->symbology->current((new DateTimeImmutable)->setTimestamp($issuedAt));

        $payloads = $this->factory->build(
            keyId: $keyId,
            orderNumber: $orderNumber,
            origin: $origin,
            destination: $destination,
            issuedAt: $issuedAt,
            lines: array_map(
                static fn (OrderLineInput $line): array => [
                    'sku' => $line->sku,
                    'quantity' => $line->quantity,
                    'piecesPerUnit' => $line->piecesPerUnit,
                ],
                array_values($lines),
            ),
        );

        $labelIds = [];
        foreach ($payloads as $payload) {
            $signed = $this->signer->sign($payload);
            $zpl = $this->renderer->render($signed, $symbology);

            $this->repository->add(EmittedLabel::of($orderNumber, $signed, $zpl, $issuedAt));
            $labelIds[] = $payload->id;
        }

        $this->recordEvents->record(
            [new LabelsEmitted($orderNumber, $keyId, $labelIds, $preEmitted, $issuedAt)],
            'labels.order',
        );

        return $labelIds;
    }
}
