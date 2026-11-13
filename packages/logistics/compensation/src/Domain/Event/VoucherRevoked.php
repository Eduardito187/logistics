<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se anuló (revocó) un vale antes de canjearse. Propaga la desactivación a Magento por outbox.
 */
final readonly class VoucherRevoked extends BaseDomainEvent
{
    public function __construct(
        string $voucherId,
        public string $actor,
        public string $reason,
        public int $amountCents,
        public string $currency,
    ) {
        parent::__construct($voucherId);
    }

    public function eventName(): string
    {
        return 'compensation.voucher_revoked';
    }

    public function payload(): array
    {
        return [
            'actor' => $this->actor,
            'reason' => $this->reason,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
        ];
    }
}
