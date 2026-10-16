<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\ValueObject;

use Logistics\Inbound\Domain\Exception\InboundException;

/**
 * Línea de un goods receipt: el SKU recibido, su desglose de cantidades (ReceivedQuantity) y el resultado
 * del control de calidad (QcOutcome). Value object inmutable.
 *
 * Invariante de coherencia QC ↔ cantidades:
 *  - `Accepted`      ⇒ nada rechazado ni dañado (todo entra).
 *  - `Rejected`      ⇒ nada aceptado (nada entra).
 *  - `PartialDamage` ⇒ hay aceptado Y hay dañado (parte entra, parte no).
 */
final readonly class ReceiptLine
{
    private function __construct(
        public string $sku,
        public ReceivedQuantity $quantity,
        public QcOutcome $qcOutcome,
    ) {}

    public static function of(string $sku, ReceivedQuantity $quantity, QcOutcome $qcOutcome): self
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw InboundException::because('El SKU de la línea recibida no puede estar vacío.');
        }

        self::assertCoherent($quantity, $qcOutcome);

        return new self($sku, $quantity, $qcOutcome);
    }

    /**
     * Cantidad aceptada que efectivamente pasa a stock y se etiqueta (punto de convergencia).
     */
    public function acceptedQuantity(): int
    {
        return $this->quantity->accepted;
    }

    public function equals(self $other): bool
    {
        return $this->sku === $other->sku
            && $this->quantity->equals($other->quantity)
            && $this->qcOutcome === $other->qcOutcome;
    }

    /**
     * @return array{sku: string, quantity: array{accepted: int, rejected: int, damaged: int}, qc_outcome: string}
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'quantity' => $this->quantity->toArray(),
            'qc_outcome' => $this->qcOutcome->value,
        ];
    }

    private static function assertCoherent(ReceivedQuantity $quantity, QcOutcome $outcome): void
    {
        switch ($outcome) {
            case QcOutcome::Accepted:
                if ($quantity->rejected > 0 || $quantity->damaged > 0) {
                    throw InboundException::because('Una línea "Accepted" no puede tener piezas rechazadas ni dañadas.');
                }
                break;
            case QcOutcome::Rejected:
                if ($quantity->accepted > 0) {
                    throw InboundException::because('Una línea "Rejected" no puede tener piezas aceptadas.');
                }
                break;
            case QcOutcome::PartialDamage:
                if ($quantity->accepted <= 0 || $quantity->damaged <= 0) {
                    throw InboundException::because('Una línea "Partial damage" debe tener piezas aceptadas y dañadas.');
                }
                break;
        }
    }
}
