<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\ReadModel;

use Logistics\Settlement\Domain\Model\SellerSettlement;
use Logistics\Settlement\Domain\ValueObject\SettlementLine;

/**
 * Proyección de lectura de una liquidación de seller para listados y API. Primitivos: no expone objetos
 * de dominio.
 */
final readonly class SettlementView
{
    /**
     * @param list<array{shipment_id: string, dispatch_reference: string, base_amount_cents: int, currency: string}>                                                                                             $lines
     * @param array{commission_basis_points: int, commission_cents: int, fixed_fee_cents: int, storage_fee_cents: int, fulfillment_fee_cents: int, last_mile_fee_cents: int, total_cents: int, currency: string} $fees
     */
    private function __construct(
        public string $id,
        public string $sellerId,
        public string $periodFrom,
        public string $periodTo,
        public array $lines,
        public int $grossCents,
        public array $fees,
        public int $netCents,
        public string $currency,
        public string $status,
        public ?string $paymentReference,
        // Nombre legible del seller (tabla sellers.legal_name) resuelto en el QueryHandler.
        // Null si el seller ya no existe; el UUID crudo se mantiene en seller_id.
        public ?string $sellerName = null,
    ) {}

    public static function fromDomain(SellerSettlement $settlement, ?string $sellerName = null): self
    {
        return new self(
            $settlement->id()->toString(),
            $settlement->sellerId(),
            $settlement->period()->fromString(),
            $settlement->period()->toStringEnd(),
            array_values(array_map(
                static fn (SettlementLine $line): array => $line->toArray(),
                $settlement->lines(),
            )),
            $settlement->gross()->cents,
            $settlement->fees()->toArray(),
            $settlement->net()->cents,
            $settlement->net()->currency,
            $settlement->status()->value,
            $settlement->paymentReference(),
            $sellerName,
        );
    }

    /**
     * @return array{id: string, seller_id: string, seller_name: string|null, period_from: string, period_to: string, lines: list<array{shipment_id: string, dispatch_reference: string, base_amount_cents: int, currency: string}>, gross_cents: int, fees: array{commission_basis_points: int, commission_cents: int, fixed_fee_cents: int, storage_fee_cents: int, fulfillment_fee_cents: int, last_mile_fee_cents: int, total_cents: int, currency: string}, net_cents: int, currency: string, status: string, payment_reference: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'seller_id' => $this->sellerId,
            'seller_name' => $this->sellerName,
            'period_from' => $this->periodFrom,
            'period_to' => $this->periodTo,
            'lines' => $this->lines,
            'gross_cents' => $this->grossCents,
            'fees' => $this->fees,
            'net_cents' => $this->netCents,
            'currency' => $this->currency,
            'status' => $this->status,
            'payment_reference' => $this->paymentReference,
        ];
    }
}
