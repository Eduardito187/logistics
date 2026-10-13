<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Capacity\Domain\Exception\CapacityException;

/**
 * Franja horaria de un cupo, p. ej. "09:00-13:00". Se normaliza a 'HH:MM-HH:MM' y se exige que el
 * inicio sea estrictamente anterior al fin (dentro del mismo día; no se admiten franjas que crucen
 * la medianoche).
 */
final readonly class TimeSlot
{
    private const string PATTERN = '/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/';

    private function __construct(
        public string $start,
        public string $end,
    ) {}

    public static function fromString(string $value): self
    {
        $normalized = trim($value);
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw CapacityException::because("franja horaria con formato inválido (HH:MM-HH:MM): {$value}");
        }

        [$start, $end] = explode('-', $normalized);
        if (self::minutes($start) >= self::minutes($end)) {
            throw CapacityException::because("la franja debe empezar antes de terminar: {$value}");
        }

        return new self($start, $end);
    }

    public function equals(self $other): bool
    {
        return $this->start === $other->start && $this->end === $other->end;
    }

    public function toString(): string
    {
        return "{$this->start}-{$this->end}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Minutos desde la medianoche de una hora 'HH:MM' (ya validada por el patrón).
     */
    private static function minutes(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);

        return ((int) $h) * 60 + (int) $m;
    }
}
