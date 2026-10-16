<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Application\ReadModel;

/**
 * Vista de una liquidación del seller. Forma primitiva (montos en centavos enteros + moneda).
 */
final readonly class SellerSettlementView
{
    public function __construct(
        public string $id,
        public string $sellerId,
        public string $periodFrom,
        public string $periodTo,
        public int $grossCents,
        public string $currency,
        public int $commissionBasisPoints,
        public int $commissionCents,
        public int $fixedFeeCents,
        public int $storageFeeCents,
        public int $fulfillmentFeeCents,
        public int $lastMileFeeCents,
        public int $netCents,
        public string $status,
        public ?string $paymentReference,
    ) {}

    /**
     * Construye la vista desde una fila cruda de `seller_settlements` (stdClass de DB::table).
     */
    public static function fromRow(object $row): self
    {
        return new self(
            id: self::str($row, 'id'),
            sellerId: self::str($row, 'seller_id'),
            periodFrom: self::str($row, 'period_from'),
            periodTo: self::str($row, 'period_to'),
            grossCents: self::int($row, 'gross_cents'),
            currency: self::str($row, 'currency'),
            commissionBasisPoints: self::int($row, 'commission_basis_points'),
            commissionCents: self::int($row, 'commission_cents'),
            fixedFeeCents: self::int($row, 'fixed_fee_cents'),
            storageFeeCents: self::int($row, 'storage_fee_cents'),
            fulfillmentFeeCents: self::int($row, 'fulfillment_fee_cents'),
            lastMileFeeCents: self::int($row, 'last_mile_fee_cents'),
            netCents: self::int($row, 'net_cents'),
            status: self::str($row, 'status'),
            paymentReference: self::nullableStr($row, 'payment_reference'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'seller_id' => $this->sellerId,
            'period_from' => $this->periodFrom,
            'period_to' => $this->periodTo,
            'gross_cents' => $this->grossCents,
            'currency' => $this->currency,
            'commission_basis_points' => $this->commissionBasisPoints,
            'commission_cents' => $this->commissionCents,
            'fixed_fee_cents' => $this->fixedFeeCents,
            'storage_fee_cents' => $this->storageFeeCents,
            'fulfillment_fee_cents' => $this->fulfillmentFeeCents,
            'last_mile_fee_cents' => $this->lastMileFeeCents,
            'net_cents' => $this->netCents,
            'status' => $this->status,
            'payment_reference' => $this->paymentReference,
        ];
    }

    private static function str(object $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableStr(object $row, string $field): ?string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    private static function int(object $row, string $field): int
    {
        $value = $row->{$field} ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
