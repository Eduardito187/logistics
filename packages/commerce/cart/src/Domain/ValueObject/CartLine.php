<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\ValueObject;

use Commerce\Cart\Domain\Exception\CartException;

/**
 * Línea del carrito: un SKU con su nombre de producto, precio unitario (en centavos) y cantidad.
 *
 * Es un value object que vive DENTRO del agregado Cart (no tiene identidad propia ni se consulta de
 * forma independiente): por eso se persiste como JSON en la fila del carrito, no en tabla aparte. El
 * precio unitario viaja YA resuelto (lo calculó `pricing`/storefront): el carrito no consulta precios.
 */
final readonly class CartLine
{
    private function __construct(
        public Sku $sku,
        public string $productName,
        public int $unitPriceCents,
        public int $qty,
    ) {}

    public static function of(Sku $sku, string $productName, int $unitPriceCents, int $qty): self
    {
        $productName = trim($productName);
        if ($productName === '') {
            throw CartException::because('El nombre del producto de la línea no puede estar vacío.');
        }
        if ($unitPriceCents < 0) {
            throw CartException::because('El precio unitario no puede ser negativo.');
        }
        if ($qty < 1) {
            throw CartException::because('La cantidad de la línea debe ser al menos 1.');
        }

        return new self($sku, $productName, $unitPriceCents, $qty);
    }

    /**
     * Reconstituye una línea desde su forma primitiva (columna JSON).
     *
     * @param array{sku: string, product_name: string, unit_price_cents: int, qty: int} $data
     */
    public static function fromArray(array $data): self
    {
        return self::of(
            Sku::of($data['sku']),
            $data['product_name'],
            $data['unit_price_cents'],
            $data['qty'],
        );
    }

    /**
     * Devuelve una copia de la línea con otra cantidad (las líneas son inmutables).
     */
    public function withQty(int $qty): self
    {
        return self::of($this->sku, $this->productName, $this->unitPriceCents, $qty);
    }

    public function subtotalCents(): int
    {
        return $this->unitPriceCents * $this->qty;
    }

    /**
     * @return array{sku: string, product_name: string, unit_price_cents: int, qty: int, subtotal_cents: int}
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku->value,
            'product_name' => $this->productName,
            'unit_price_cents' => $this->unitPriceCents,
            'qty' => $this->qty,
            'subtotal_cents' => $this->subtotalCents(),
        ];
    }
}
