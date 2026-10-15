<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Capacity\Domain\Exception\CapacityException;

/**
 * Fecha de un cupo (sin hora): el día al que aplica la franja. Se guarda normalizada a 'Y-m-d' para
 * que dos cupos del mismo día comparen por valor sin depender de la hora ni de la zona horaria.
 */
final readonly class SlotDate
{
    private const string FORMAT = 'Y-m-d';

    private function __construct(public string $value) {}

    /**
     * Construye desde una cadena 'Y-m-d'. Valida que sea una fecha real (no 2026-02-31).
     */
    public static function fromString(string $value): self
    {
        $normalized = trim($value);
        $date = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $normalized);
        if ($date === false || $date->format(self::FORMAT) !== $normalized) {
            throw CapacityException::because("fecha de cupo inválida (se espera Y-m-d): {$value}");
        }

        return new self($normalized);
    }

    public static function fromDateTime(DateTimeImmutable $date): self
    {
        return new self($date->format(self::FORMAT));
    }

    public function toDateTime(): DateTimeImmutable
    {
        // El valor ya está validado como 'Y-m-d'; createFromFormat no puede fallar acá.
        return DateTimeImmutable::createFromFormat('!'.self::FORMAT, $this->value)
            ?: new DateTimeImmutable($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * ¿Esta fecha es estrictamente posterior a la otra? El valor está normalizado a 'Y-m-d', cuyo orden
     * lexicográfico coincide con el cronológico, así que basta comparar las cadenas.
     */
    public function isAfter(self $other): bool
    {
        return $this->value > $other->value;
    }

    /**
     * ¿Esta fecha es anterior o igual a la otra?
     */
    public function isOnOrBefore(self $other): bool
    {
        return $this->value <= $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
