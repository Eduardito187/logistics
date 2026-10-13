<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Fecha de reparto de un plan (día calendario, sin hora). Un plan es "para el día X".
 *
 * Se normaliza a medianoche en UTC para que dos PlanDate del mismo día sean iguales sin importar la
 * hora o la zona con la que se construyeron. La representación canónica es 'Y-m-d'.
 */
final readonly class PlanDate
{
    private const FORMAT = 'Y-m-d';

    private function __construct(public DateTimeImmutable $value) {}

    /**
     * Construye desde una fecha 'Y-m-d' (p. ej. '2026-10-13').
     */
    public static function fromString(string $date): self
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.self::FORMAT, trim($date), new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw PlanningException::because("fecha de plan inválida (se espera Y-m-d): {$date}");
        }

        return new self($parsed);
    }

    /**
     * Construye desde un DateTimeInterface, descartando la hora (se queda con el día en UTC).
     */
    public static function of(DateTimeInterface $moment): self
    {
        return self::fromString($moment->format(self::FORMAT));
    }

    public function equals(self $other): bool
    {
        return $this->toString() === $other->toString();
    }

    public function toString(): string
    {
        return $this->value->format(self::FORMAT);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
