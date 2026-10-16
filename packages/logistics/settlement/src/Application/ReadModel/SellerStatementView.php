<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\ReadModel;

/**
 * Estado de cuenta (statement) de un seller: resumen agregado de sus liquidaciones. Primitivos.
 *
 * Agrega todas las liquidaciones del seller: cuánto se le ha liquidado en bruto, cuánto en fees retenidos,
 * cuánto neto, y cuánto ya está pagado vs. pendiente (borrador + confirmado). Es el lado de lectura que
 * alimentará el portal del seller (Fase 5) sin tocar el lado de escritura.
 */
final readonly class SellerStatementView
{
    /**
     * @param list<array<string, mixed>> $settlements liquidaciones del seller (cada una vía SettlementView::toArray)
     */
    private function __construct(
        public string $sellerId,
        public ?string $sellerName,
        public int $settlementCount,
        public int $grossCents,
        public int $feesTotalCents,
        public int $netCents,
        public int $paidCents,
        public int $pendingCents,
        public string $currency,
        public array $settlements,
    ) {}

    /**
     * @param list<array<string, mixed>> $settlements
     */
    public static function of(
        string $sellerId,
        ?string $sellerName,
        int $settlementCount,
        int $grossCents,
        int $feesTotalCents,
        int $netCents,
        int $paidCents,
        int $pendingCents,
        string $currency,
        array $settlements,
    ): self {
        return new self(
            $sellerId,
            $sellerName,
            $settlementCount,
            $grossCents,
            $feesTotalCents,
            $netCents,
            $paidCents,
            $pendingCents,
            $currency,
            $settlements,
        );
    }

    /**
     * @return array{seller_id: string, seller_name: string|null, settlement_count: int, gross_cents: int, fees_total_cents: int, net_cents: int, paid_cents: int, pending_cents: int, currency: string, settlements: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'seller_id' => $this->sellerId,
            'seller_name' => $this->sellerName,
            'settlement_count' => $this->settlementCount,
            'gross_cents' => $this->grossCents,
            'fees_total_cents' => $this->feesTotalCents,
            'net_cents' => $this->netCents,
            'paid_cents' => $this->paidCents,
            'pending_cents' => $this->pendingCents,
            'currency' => $this->currency,
            'settlements' => $this->settlements,
        ];
    }
}
