<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Fecha de nacimiento de una persona de la flota (chofer o ayudante).
 *
 * Se compara a nivel de día; la edad se calcula contra una fecha de referencia para no depender del
 * "hoy" dentro del dominio (que debe ser determinista y testeable).
 */
final readonly class BirthDate
{
    private function __construct(
        public DateTimeImmutable $date,
    ) {}

    public static function of(DateTimeImmutable $date): self
    {
        return new self($date->setTime(0, 0, 0));
    }

    /**
     * Edad en años cumplidos a la fecha de referencia. Si la fecha de nacimiento es futura, devuelve 0.
     */
    public function ageAt(DateTimeImmutable $ref): int
    {
        $refMidnight = $ref->setTime(0, 0, 0);
        if ($this->date > $refMidnight) {
            return 0;
        }

        return $this->date->diff($refMidnight)->y;
    }

    public function toString(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
