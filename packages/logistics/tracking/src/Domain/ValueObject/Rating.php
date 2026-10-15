<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Calificación de la entrega por parte del cliente: un entero de 1 a 5 estrellas.
 *
 * Es un value object inmutable (PHP puro). El rango se valida en la construcción: nunca existe un
 * Rating fuera de 1..5. La lógica de NPS (promotor/pasivo/detractor) se deriva de este valor.
 */
final readonly class Rating
{
    public const int MIN = 1;

    public const int MAX = 5;

    private function __construct(public int $value) {}

    public static function of(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw TrackingException::because(
                'la calificación debe estar entre '.self::MIN.' y '.self::MAX." (recibido: {$value})",
            );
        }

        return new self($value);
    }

    /**
     * Un promotor (5) recomienda activamente; cuenta a favor del NPS.
     */
    public function isPromoter(): bool
    {
        return $this->value >= 5;
    }

    /**
     * Un detractor (1-3) quedó insatisfecho; resta en el NPS.
     */
    public function isDetractor(): bool
    {
        return $this->value <= 3;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
