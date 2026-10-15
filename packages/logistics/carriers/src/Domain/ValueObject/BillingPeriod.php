<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

use Logistics\Carriers\Domain\Exception\CarriersException;

/**
 * Período de facturación mensual de una liquidación, en formato `YYYY-MM`.
 *
 * Se guarda como texto normalizado (año-mes) a propósito: las liquidaciones de transportistas se
 * agrupan por mes, no por día. El VO garantiza que el período esté bien formado.
 */
final readonly class BillingPeriod
{
    private function __construct(
        public int $year,
        public int $month,
    ) {}

    public static function of(string $period): self
    {
        $period = trim($period);
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $matches) !== 1) {
            throw CarriersException::because("Período de facturación inválido (se espera YYYY-MM): {$period}.");
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    public function toString(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }
}
