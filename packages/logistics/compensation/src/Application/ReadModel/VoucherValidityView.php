<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\ReadModel;

use Logistics\Compensation\Domain\ValueObject\Money;

/**
 * Proyección de validez de un vale para el POS / checkout: si es canjeable, por cuánto y hasta cuándo, o el
 * motivo del rechazo. Primitivos; el monto se expone en centavos y en formato legible (nunca null/vacío).
 */
final readonly class VoucherValidityView
{
    private function __construct(
        public bool $valid,
        public int $amountCents,
        public string $currency,
        public string $amountFormatted,
        public ?string $expiresAt,
        public string $status,
        public ?string $reasonCode,
    ) {}

    public static function valid(Money $amount, string $expiresAt): self
    {
        return new self(true, $amount->cents, $amount->currency, $amount->formatted(), $expiresAt, 'issued', null);
    }

    public static function invalid(string $reasonCode, string $status, Money $amount): self
    {
        return new self(false, $amount->cents, $amount->currency, $amount->formatted(), null, $status, $reasonCode);
    }

    public static function notFound(): self
    {
        return new self(false, 0, Money::DEFAULT_CURRENCY, Money::zero()->formatted(), null, 'not_found', 'voucher_not_found');
    }

    /**
     * @return array{valid: bool, amount_cents: int, currency: string, amount_formatted: string, expires_at: ?string, status: string, reason: ?string}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'amount_formatted' => $this->amountFormatted,
            'expires_at' => $this->expiresAt,
            'status' => $this->status,
            'reason' => $this->reasonCode,
        ];
    }
}
