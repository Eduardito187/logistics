<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\ValueObject;

use Logistics\Settlement\Domain\Exception\SettlementException;

/**
 * Una línea de la liquidación: un despacho del seller dentro del periodo, con su monto base.
 *
 * El despacho se referencia por dos primitivos (sin FK dura hacia `shipments`, que vive en otro contexto):
 * - `shipmentId`: el UUID del envío del contexto `shipments` (trazabilidad).
 * - `dispatchReference`: la referencia legible del despacho (id externo del pedido), para el statement.
 *
 * El `baseAmount` es el valor del despacho (venta) sobre el que se calcula la comisión del marketplace.
 */
final readonly class SettlementLine
{
    private function __construct(
        public string $shipmentId,
        public string $dispatchReference,
        public Money $baseAmount,
    ) {}

    public static function of(string $shipmentId, string $dispatchReference, Money $baseAmount): self
    {
        $shipmentId = trim($shipmentId);
        if ($shipmentId === '') {
            throw SettlementException::because('La línea de liquidación requiere un envío.');
        }
        $dispatchReference = trim($dispatchReference);
        if ($dispatchReference === '') {
            throw SettlementException::because('La línea de liquidación requiere una referencia de despacho.');
        }

        return new self($shipmentId, $dispatchReference, $baseAmount);
    }

    /**
     * @return array{shipment_id: string, dispatch_reference: string, base_amount_cents: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'dispatch_reference' => $this->dispatchReference,
            'base_amount_cents' => $this->baseAmount->cents,
            'currency' => $this->baseAmount->currency,
        ];
    }
}
