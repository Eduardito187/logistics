<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * "N de M": la unidad 1 de 2 del mismo producto, o la pieza (caja) 2 de 3 de una unidad.
 *
 * Propia de este contexto a propósito: aunque el concepto se parece al de Etiquetas, cada bounded
 * context define su propio modelo y no comparte value objects de dominio con otro (ver arquitectura).
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
            throw ShipmentsException::because("total fuera de rango (1..999): {$total}");
        }
        if ($index < 1 || $index > $total) {
            throw ShipmentsException::because("índice {$index} fuera de 1..{$total}");
        }

        return new self($index, $total);
    }

    /**
     * Pieza o unidad única (1 de 1): el caso por defecto cuando no hay desglose.
     */
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

    public function equals(self $other): bool
    {
        return $this->index === $other->index && $this->total === $other->total;
    }
}
