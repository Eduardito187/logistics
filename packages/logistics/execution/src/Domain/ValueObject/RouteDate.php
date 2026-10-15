<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Día de la ruta del chofer (día calendario, sin hora). Una ruta es "la del camión para el día X".
 *
 * Se normaliza a medianoche en UTC para que dos RouteDate del mismo día sean iguales sin importar la
 * hora ni la zona con la que se construyeron. La representación canónica es 'Y-m-d'.
 */
final readonly class RouteDate
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
            throw ExecutionException::because("fecha de ruta inválida (se espera Y-m-d): {$date}");
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

    /**
     * Día siguiente a esta fecha de ruta. Lo usa la reprogramación por incidente MAYOR: los envíos
     * pendientes se replanifican para el día de después.
     */
    public function nextDay(): self
    {
        return new self($this->value->modify('+1 day'));
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
