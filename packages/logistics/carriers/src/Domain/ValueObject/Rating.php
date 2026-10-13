<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

use Logistics\Carriers\Domain\Exception\CarriersException;

/**
 * Calificación de desempeño de un transportista en una escala entera de 1 a 5.
 *
 * El promedio de calificaciones vive en el agregado Carrier; este VO representa una calificación suelta
 * y garantiza que siempre esté en rango.
 */
final readonly class Rating
{
    private const MIN = 1;

    private const MAX = 5;

    private function __construct(
        public int $value,
    ) {}

    public static function of(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw CarriersException::because(
                'La calificación debe estar entre '.self::MIN.' y '.self::MAX.": {$value}.",
            );
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
