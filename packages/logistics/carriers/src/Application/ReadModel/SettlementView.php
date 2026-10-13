<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\ReadModel;

use Logistics\Carriers\Domain\Model\Settlement;

/**
 * Proyección de lectura de una liquidación para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class SettlementView
{
    /**
     * @param list<array{type: string, reference: string}> $documents
     */
    private function __construct(
        public string $id,
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public string $corridorType,
        public string $period,
        public int $amountCents,
        public string $currency,
        public string $reference,
        public string $status,
        public ?string $disputeReason,
        public ?string $paymentReference,
        public array $documents,
        // Nombre legible del transportista (tabla carriers.name) resuelto en el QueryHandler.
        // Null si el transportista ya no existe; el UUID crudo se mantiene en carrier_id.
        public ?string $carrierName = null,
    ) {}

    public static function fromDomain(Settlement $settlement, ?string $carrierName = null): self
    {
        return new self(
            $settlement->id()->toString(),
            $settlement->carrierId(),
            $settlement->corridor()->from,
            $settlement->corridor()->to,
            $settlement->corridor()->type,
            $settlement->period()->toString(),
            $settlement->amount()->cents,
            $settlement->amount()->currency,
            $settlement->reference(),
            $settlement->status()->value,
            $settlement->disputeReason(),
            $settlement->paymentReference(),
            $settlement->documents(),
            $carrierName,
        );
    }

    /**
     * @return array{id: string, carrier_id: string, carrier_name: string|null, corridor_from: string, corridor_to: string, corridor_type: string, period: string, amount_cents: int, currency: string, reference: string, status: string, dispute_reason: string|null, payment_reference: string|null, documents: list<array{type: string, reference: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'carrier_id' => $this->carrierId,
            'carrier_name' => $this->carrierName,
            'corridor_from' => $this->corridorFrom,
            'corridor_to' => $this->corridorTo,
            'corridor_type' => $this->corridorType,
            'period' => $this->period,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'status' => $this->status,
            'dispute_reason' => $this->disputeReason,
            'payment_reference' => $this->paymentReference,
            'documents' => $this->documents,
        ];
    }
}
