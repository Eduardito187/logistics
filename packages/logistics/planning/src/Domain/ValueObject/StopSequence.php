<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

use Logistics\Planning\Domain\Exception\PlanningException;

/**
 * Posición de una parada dentro del plan: 1 es la primera entrega, 2 la segunda, etc.
 *
 * Es 1-based a propósito: refleja el orden humano de reparto, no un índice de array.
 */
final readonly class StopSequence
{
    private function __construct(public int $value) {}

    public static function of(int $value): self
    {
        if ($value < 1) {
            throw PlanningException::because("la secuencia de parada debe ser >= 1: {$value}");
        }

        return new self($value);
    }

    public static function first(): self
    {
        return new self(1);
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
