<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un vale venció sin canjearse. Lo consume el sync a Magento (desactivar la regla) y los reportes.
 */
final readonly class VoucherExpired extends BaseDomainEvent
{
    public function __construct(
        string $voucherId,
        public int $amountCents,
        public string $currency,
    ) {
        parent::__construct($voucherId);
    }

    public function eventName(): string
    {
        return 'compensation.voucher_expired';
    }

    public function payload(): array
    {
        return [
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
        ];
    }
}
