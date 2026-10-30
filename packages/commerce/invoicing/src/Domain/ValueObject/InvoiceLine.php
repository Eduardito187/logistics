<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\ValueObject;

use Commerce\Invoicing\Domain\Exception\InvoicingException;

/**
 * Línea de una factura: instantánea inmutable de un ítem facturado (descripción, cantidad, precio
 * unitario en centavos y total de línea). El total de línea es precio unitario × cantidad, congelado al
 * emitir. Value object dentro del agregado Invoice (persistido como JSONB en la fila).
 *
 * Los montos son bruto (IVA incluido): el total de la factura es la suma de los `lineTotalCents`, y de
 * ese bruto el agregado separa neto e IVA con `BolivianTax`.
 */
final readonly class InvoiceLine
{
    private function __construct(
        public string $description,
        public int $quantity,
        public int $unitPriceCents,
        public int $lineTotalCents,
    ) {}

    public static function of(string $description, int $quantity, int $unitPriceCents): self
    {
        $description = trim($description);
        if ($description === '') {
            throw InvoicingException::because('La descripción de la línea no puede estar vacía.');
        }
        if ($quantity < 1) {
            throw InvoicingException::because('La cantidad de la línea debe ser al menos 1.');
        }
        if ($unitPriceCents < 0) {
            throw InvoicingException::because('El precio unitario no puede ser negativo.');
        }

        return new self($description, $quantity, $unitPriceCents, $unitPriceCents * $quantity);
    }

    /**
     * Reconstruye una línea desde su forma persistida. El `line_total_cents` guardado se ignora: se
     * recalcula desde precio × cantidad (fuente de verdad), garantizando coherencia.
     *
     * @param array{description: string, quantity: int, unit_price_cents: int, line_total_cents: int} $data
     */
    public static function fromArray(array $data): self
    {
        return self::of($data['description'], $data['quantity'], $data['unit_price_cents']);
    }

    /**
     * @return array{description: string, quantity: int, unit_price_cents: int, line_total_cents: int}
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price_cents' => $this->unitPriceCents,
            'line_total_cents' => $this->lineTotalCents,
        ];
    }
}
