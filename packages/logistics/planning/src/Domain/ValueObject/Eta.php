<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Hora estimada de llegada a una parada: el instante de arribo más los minutos de viaje acumulados
 * desde la salida del depósito.
 *
 * Se guardan ambos datos juntos porque el motor de rutas los produce juntos: el instante sirve para
 * mostrarle al cliente/operador cuándo llega, y los minutos acumulados para comparar y recomputar
 * rutas sin depender de la fecha absoluta.
 */
final readonly class Eta
{
    private function __construct(
        public DateTimeImmutable $arrivalAt,
        public int $minutesFromDeparture,
    ) {}

    public static function at(DateTimeImmutable $arrivalAt, int $minutesFromDeparture): self
    {
        if ($minutesFromDeparture < 0) {
            throw PlanningException::because("los minutos de ETA no pueden ser negativos: {$minutesFromDeparture}");
        }

        return new self($arrivalAt, $minutesFromDeparture);
    }

    public function equals(self $other): bool
    {
        return $this->minutesFromDeparture === $other->minutesFromDeparture
            && $this->arrivalAt->getTimestamp() === $other->arrivalAt->getTimestamp();
    }
}
