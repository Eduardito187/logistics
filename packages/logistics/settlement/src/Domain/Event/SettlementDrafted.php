<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se armó el borrador de una liquidación de seller: agrega los despachos del periodo con su bruto, los
 * fees aplicados y el neto a pagar (pendiente de confirmación).
 */
final readonly class SettlementDrafted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $sellerId,
        public string $periodFrom,
        public string $periodTo,
        public int $lineCount,
        public int $grossCents,
        public int $feesTotalCents,
        public int $netCents,
        public string $currency,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'settlement.drafted';
    }

    public function payload(): array
    {
        return [
            'seller_id' => $this->sellerId,
            'period_from' => $this->periodFrom,
            'period_to' => $this->periodTo,
            'line_count' => $this->lineCount,
            'gross_cents' => $this->grossCents,
            'fees_total_cents' => $this->feesTotalCents,
            'net_cents' => $this->netCents,
            'currency' => $this->currency,
        ];
    }
}
