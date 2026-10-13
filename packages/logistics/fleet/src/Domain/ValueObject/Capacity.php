<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Capacidad de carga de un vehículo: peso en kilogramos y volumen en metros cúbicos.
 *
 * El peso se guarda como entero de kilogramos (no admite fracciones de kilo) para que las sumas de
 * capacidad sean exactas; el volumen es decimal con tres decimales (hasta el litro). Ambos no pueden
 * ser negativos. El dominio no decide qué carga entra en el camión; eso es de planning.
 */
final readonly class Capacity
{
    private function __construct(
        public int $kilograms,
        public float $cubicMeters,
    ) {}

    /**
     * @param int   $kilograms   peso máximo en kilogramos (entero, >= 0)
     * @param float $cubicMeters volumen máximo en metros cúbicos (>= 0)
     */
    public static function of(int $kilograms, float $cubicMeters): self
    {
        if ($kilograms < 0) {
            throw FleetException::because("La capacidad en kg no puede ser negativa: {$kilograms}.");
        }
        if ($cubicMeters < 0.0) {
            throw FleetException::because("La capacidad en m³ no puede ser negativa: {$cubicMeters}.");
        }

        return new self($kilograms, round($cubicMeters, 3));
    }

    /**
     * Capacidad cero: útil como punto de partida al sumar o para vehículos sin carga declarada.
     */
    public static function zero(): self
    {
        return new self(0, 0.0);
    }

    /**
     * @return array{kg: int, m3: float}
     */
    public function toArray(): array
    {
        return ['kg' => $this->kilograms, 'm3' => $this->cubicMeters];
    }
}
