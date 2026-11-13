<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Compensation\Domain\Exception\CompensationException;

/**
 * Porcentaje en basis points (bps): 0..10000, donde 10000 = 100 %. Entero, sin floats.
 */
final readonly class Percentage
{
    public const int MAX_BPS = 10000;

    private function __construct(public int $bps) {}

    public static function ofBasisPoints(int $bps): self
    {
        if ($bps < 0 || $bps > self::MAX_BPS) {
            throw CompensationException::because(
                'El porcentaje debe estar entre 0 y '.self::MAX_BPS." bps (recibido: {$bps}).",
            );
        }

        return new self($bps);
    }

    /**
     * Aplica el porcentaje a un monto, redondeando SIEMPRE hacia abajo (floor): nunca regala de más.
     */
    public function applyTo(Money $base): Money
    {
        return Money::of(intdiv($base->cents * $this->bps, self::MAX_BPS), $base->currency);
    }
}
