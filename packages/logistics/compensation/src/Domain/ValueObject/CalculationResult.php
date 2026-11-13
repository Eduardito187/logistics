<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

/**
 * Resultado del {@see \Logistics\Compensation\Domain\Service\VoucherCalculator}: el monto nominal (antes de
 * topes), el efectivo (después de topes y redondeo), si es compensable y la traza para auditoría.
 *
 * Si `compensable` es false, `notCompensableReason` explica por qué (p. ej. "E < mínimo") y el efectivo es 0.
 */
final readonly class CalculationResult
{
    private function __construct(
        public Money $nominal,
        public Money $effective,
        public bool $compensable,
        public ?string $notCompensableReason,
        public CalculationTrace $trace,
    ) {}

    public static function compensable(Money $nominal, Money $effective, CalculationTrace $trace): self
    {
        return new self($nominal, $effective, true, null, $trace);
    }

    public static function notCompensable(Money $nominal, string $reason, CalculationTrace $trace): self
    {
        return new self($nominal, Money::zero($nominal->currency), false, $reason, $trace);
    }
}
