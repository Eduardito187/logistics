<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Promise\Domain\Exception\PromiseException;

/**
 * Franja de entrega: una fecha (día, sin hora) más una etiqueta de franja horaria dentro de ese día
 * (p. ej. "AM", "PM", "09:00-13:00"). Es la unidad que se ofrece, se promete y contra la que se reserva
 * cupo.
 *
 * La fecha se normaliza a medianoche para que dos slots del mismo día comparen igual sin importar la
 * hora con la que se construyeron. La etiqueta la define el contexto de capacidad; aquí solo se valida
 * su forma (no vacía, alfabeto acotado), sin interpretarla.
 */
final readonly class DeliverySlot
{
    private const string LABEL_PATTERN = '/^[A-Z0-9:\-]+$/';

    private const int MAX_LABEL_LENGTH = 20;

    public DateTimeImmutable $date;

    public string $timeSlot;

    private function __construct(DateTimeImmutable $date, string $timeSlot)
    {
        // Normaliza a medianoche: el slot es un día + franja, no un instante.
        $this->date = $date->setTime(0, 0, 0);
        $this->timeSlot = $timeSlot;
    }

    public static function of(DateTimeImmutable $date, string $timeSlot): self
    {
        $normalized = strtoupper(trim($timeSlot));
        if ($normalized === '') {
            throw PromiseException::because('la franja horaria no puede estar vacía');
        }
        if (strlen($normalized) > self::MAX_LABEL_LENGTH) {
            throw PromiseException::because('la franja horaria excede '.self::MAX_LABEL_LENGTH.' caracteres');
        }
        if (preg_match(self::LABEL_PATTERN, $normalized) !== 1) {
            throw PromiseException::because("franja horaria con formato inválido: {$timeSlot}");
        }

        return new self($date, $normalized);
    }

    /**
     * Fecha del día en formato ISO (Y-m-d), tal como se persiste en la columna `slot_date`.
     */
    public function dateString(): string
    {
        return $this->date->format('Y-m-d');
    }

    public function equals(self $other): bool
    {
        return $this->dateString() === $other->dateString() && $this->timeSlot === $other->timeSlot;
    }

    /**
     * Forma canónica "YYYY-MM-DD|FRANJA", útil para logs, claves y aserciones.
     */
    public function toCanonical(): string
    {
        return $this->dateString().'|'.$this->timeSlot;
    }
}
