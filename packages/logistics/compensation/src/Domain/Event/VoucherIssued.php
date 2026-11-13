<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se emitió un vale a partir de un caso aprobado. Es el ÚNICO evento que lleva el código completo del vale
 * (lo necesitan notifications para el email y el sync a Magento). El resto de eventos nunca lo exponen.
 */
final readonly class VoucherIssued extends BaseDomainEvent
{
    public function __construct(
        string $voucherId,
        public string $caseId,
        public string $code,
        public int $amountCents,
        public string $currency,
        public ?string $holderEmail,
        public string $expiresAt,
        public ?string $orderReference,
        public string $trigger,
    ) {
        parent::__construct($voucherId);
    }

    public function eventName(): string
    {
        return 'compensation.voucher_issued';
    }

    public function payload(): array
    {
        return [
            'case_id' => $this->caseId,
            'code' => $this->code,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'holder_email' => $this->holderEmail,
            'expires_at' => $this->expiresAt,
            'order_reference' => $this->orderReference,
            'trigger' => $this->trigger,
        ];
    }
}
