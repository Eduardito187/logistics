<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Una opción de promesa ya calculada: una fecha concreta, su franja y cuánto cupo queda. Es lo que el
 * cálculo ofrece al canal como combinación válida de cobertura (día que la zona atiende, dentro del
 * tiempo base), capacidad (cupo libre) y corredor (red operativa hacia la zona).
 *
 * La fecha se guarda normalizada a medianoche: la opción es un día + franja, no un instante.
 */
final readonly class PromiseOption
{
    public DateTimeImmutable $date;

    public string $timeSlot;

    public float $available;

    public function __construct(DateTimeImmutable $date, string $timeSlot, float $available)
    {
        $this->date = $date->setTime(0, 0, 0);
        $this->timeSlot = $timeSlot;
        $this->available = $available;
    }

    public static function of(DateTimeImmutable $date, string $timeSlot, float $available): self
    {
        return new self($date, $timeSlot, $available);
    }

    /**
     * Fecha del día en formato ISO (Y-m-d).
     */
    public function dateString(): string
    {
        return $this->date->format('Y-m-d');
    }

    /**
     * Día de la semana ISO-8601 (1 = lunes … 7 = domingo).
     */
    public function weekday(): int
    {
        return (int) $this->date->format('N');
    }
}
