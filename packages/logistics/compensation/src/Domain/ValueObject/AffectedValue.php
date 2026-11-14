<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Compensation\Domain\Exception\CompensationException;

/**
 * Base del cálculo `B` (según alcance) y la base de toda la orden `B_order` (para el tope acumulado),
 * más las líneas afectadas (nombre + SKU, snapshot). Es la "foto" del valor que se compensa.
 *
 * `fromLines()` arma la base sumando el valor de las líneas; rechaza montos que no sean BOB (regla 2.1.1).
 * Para líneas sin precio (regla 6) se usa `manual()`: base declarada por el operador, solo emisión manual.
 */
final readonly class AffectedValue
{
    /**
     * @param list<AffectedLine> $lines
     */
    private function __construct(
        public Money $base,
        public Money $orderBase,
        public array $lines,
        public bool $hasDeclaredBase,
    ) {}

    /**
     * Arma la base a partir de las líneas afectadas (su suma). orderBase por defecto = base si no se pasa.
     *
     * @param list<AffectedLine> $lines
     */
    public static function fromLines(array $lines, ?Money $orderBase = null): self
    {
        if ($lines === []) {
            throw CompensationException::because('No hay líneas afectadas para calcular la base.');
        }

        $base = Money::zero();
        foreach ($lines as $line) {
            if (!$line->unitPrice->isBob()) {
                throw CompensationException::because(
                    "No hay emisión automática si la línea no es BOB (SKU {$line->sku}).",
                );
            }
            $base = $base->add($line->lineValue());
        }

        $orderBase ??= $base;
        if ($orderBase->cents < $base->cents) {
            throw CompensationException::because('La base de la orden no puede ser menor que la base afectada.');
        }

        return new self($base, $orderBase, array_values($lines), false);
    }

    /**
     * Base DECLARADA manualmente (línea sin precio, regla 6): requiere aprobación y justificación.
     *
     * @param list<AffectedLine> $lines
     */
    public static function manual(Money $base, ?Money $orderBase = null, array $lines = []): self
    {
        $orderBase ??= $base;
        if ($orderBase->cents < $base->cents) {
            throw CompensationException::because('La base de la orden no puede ser menor que la base afectada.');
        }

        return new self($base, $orderBase, array_values($lines), true);
    }

    /**
     * @return list<array{product_name: string, sku: string, affected_quantity: int, unit_price_cents: int, currency: string}>
     */
    public function linesToArray(): array
    {
        return array_map(static fn (AffectedLine $line): array => $line->toArray(), $this->lines);
    }
}
