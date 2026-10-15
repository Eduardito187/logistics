<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Posición del envío dentro de la ruta publicada del camión: 1 es la primera parada, 2 la segunda, etc.
 *
 * Es 1-based a propósito, igual que la secuencia de paradas de planning (StopSequence): refleja el
 * orden humano de reparto, no un índice de array. De esta posición se deriva cuántas paradas faltan
 * ANTES de la del cliente (stopsBefore()).
 */
final readonly class RoutePosition
{
    private function __construct(public int $value) {}

    public static function of(int $value): self
    {
        if ($value < 1) {
            throw TrackingException::because("la posición en la ruta debe ser >= 1: {$value}");
        }

        return new self($value);
    }

    /**
     * Paradas que el camión atiende ANTES de llegar a la del cliente. La primera parada no tiene
     * ninguna por delante (0); la tercera tiene dos, etc.
     */
    public function stopsBefore(): int
    {
        return $this->value - 1;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
