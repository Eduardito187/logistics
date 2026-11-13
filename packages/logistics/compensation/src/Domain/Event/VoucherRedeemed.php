<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se canjeó un vale (un solo uso). El payload NO lleva el código completo: solo referencias y el canal.
 * Lo consume el sync a Magento (desactivar la regla de carrito) y los reportes de tasa de canje.
 */
final readonly class VoucherRedeemed extends BaseDomainEvent
{
    public function __construct(
        string $voucherId,
        public string $channel,
        public string $saleRef,
        public int $amountUsedCents,
        public string $currency,
    ) {
        parent::__construct($voucherId);
    }

    public function eventName(): string
    {
        return 'compensation.voucher_redeemed';
    }

    public function payload(): array
    {
        return [
            'channel' => $this->channel,
            'sale_ref' => $this->saleRef,
            'amount_used_cents' => $this->amountUsedCents,
            'currency' => $this->currency,
        ];
    }
}
