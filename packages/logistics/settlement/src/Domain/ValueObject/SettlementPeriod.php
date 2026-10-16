<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Settlement\Domain\Exception\SettlementException;

/**
 * Periodo de liquidación: rango cerrado [desde, hasta] en días (formato `YYYY-MM-DD`).
 *
 * A diferencia del `BillingPeriod` mensual de carriers, el periodo del seller es un rango explícito: la
 * liquidación agrega los despachos del seller realizados entre ambas fechas (inclusive). El VO garantiza
 * que el rango esté bien formado y que `desde <= hasta`.
 */
final readonly class SettlementPeriod
{
    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {}

    public static function of(string $from, string $to): self
    {
        $fromDate = self::parse($from);
        $toDate = self::parse($to);

        if ($fromDate > $toDate) {
            throw SettlementException::because(
                "El inicio del periodo ({$from}) no puede ser posterior al fin ({$to}).",
            );
        }

        return new self($fromDate, $toDate);
    }

    private static function parse(string $date): DateTimeImmutable
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw SettlementException::because("Fecha de periodo inválida (se espera YYYY-MM-DD): {$date}.");
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false) {
            throw SettlementException::because("Fecha de periodo inválida (se espera YYYY-MM-DD): {$date}.");
        }

        return $parsed;
    }

    public function fromString(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toStringEnd(): string
    {
        return $this->to->format('Y-m-d');
    }

    /**
     * Representación legible del rango, p. ej. `2026-09-01 — 2026-09-30`.
     */
    public function label(): string
    {
        return $this->fromString().' — '.$this->toStringEnd();
    }

    public function equals(self $other): bool
    {
        return $this->fromString() === $other->fromString()
            && $this->toStringEnd() === $other->toStringEnd();
    }
}
