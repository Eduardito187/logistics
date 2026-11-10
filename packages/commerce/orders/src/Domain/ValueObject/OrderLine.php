<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\ValueObject;

use Commerce\Orders\Domain\Exception\OrdersException;

/**
 * Línea de una orden: instantánea inmutable del producto al momento del checkout (sku, nombre, precio
 * unitario en centavos y cantidad). El precio es una copia congelada: la orden no vuelve a consultar
 * pricing. Value object dentro del agregado Order (persistido como JSONB en la fila).
 */
final readonly class OrderLine
{
    private function __construct(
        public string $sku,
        public string $productName,
        public int $unitPriceCents,
        public int $quantity,
        // Destino de ESTA línea (multishipping): UUID del nodo de retiro (tienda/CD) al que va, o null
        // para el domicilio de la orden. Permite que una orden se reparta a varios destinos.
        public ?string $destinationNodeId = null,
    ) {}

    public static function of(string $sku, string $productName, int $unitPriceCents, int $quantity, ?string $destinationNodeId = null): self
    {
        $sku = strtoupper(trim($sku));
        if ($sku === '') {
            throw OrdersException::because('El SKU de la línea no puede estar vacío.');
        }
        $productName = trim($productName);
        if ($productName === '') {
            throw OrdersException::because('El nombre del producto de la línea no puede estar vacío.');
        }
        if ($unitPriceCents < 0) {
            throw OrdersException::because('El precio unitario no puede ser negativo.');
        }
        if ($quantity < 1) {
            throw OrdersException::because('La cantidad de la línea debe ser al menos 1.');
        }
        $destinationNodeId = $destinationNodeId !== null && trim($destinationNodeId) !== '' ? trim($destinationNodeId) : null;

        return new self($sku, $productName, $unitPriceCents, $quantity, $destinationNodeId);
    }

    /**
     * @param array{sku: string, product_name: string, unit_price_cents: int, quantity: int, destination_node_id?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['sku'],
            $data['product_name'],
            $data['unit_price_cents'],
            $data['quantity'],
            isset($data['destination_node_id']) && is_string($data['destination_node_id']) && $data['destination_node_id'] !== '' ? $data['destination_node_id'] : null,
        );
    }

    public function subtotalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }

    /**
     * @return array{sku: string, product_name: string, unit_price_cents: int, quantity: int, destination_node_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'unit_price_cents' => $this->unitPriceCents,
            'quantity' => $this->quantity,
            'destination_node_id' => $this->destinationNodeId,
        ];
    }
}
