<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

use Logistics\Labels\Domain\Exception\InvalidLabelException;

/**
 * "N de M": la unidad 1 de 2 del mismo producto, o la pieza (caja) 2 de 3 de un producto.
 *
 * Sin estos dos datos el sistema no puede detectar que se cargaron 2 de las 3 cajas de un ropero.
 */
final readonly class Fraction
{
    private function __construct(
        public int $index,
        public int $total,
    ) {}

    public static function of(int $index, int $total): self
    {
        if ($total < 1 || $total > 999) {
            throw InvalidLabelException::because("total fuera de rango (1..999): {$total}");
        }
        if ($index < 1 || $index > $total) {
            throw InvalidLabelException::because("índice {$index} fuera de 1..{$total}");
        }

        return new self($index, $total);
    }

    public static function single(): self
    {
        return new self(1, 1);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function toArray(): array
    {
        return [$this->index, $this->total];
    }

    public function toCanonical(): string
    {
        return "{$this->index}/{$this->total}";
    }
}
