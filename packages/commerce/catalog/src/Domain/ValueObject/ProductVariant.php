<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\ValueObject;

use Commerce\Catalog\Domain\Exception\CatalogException;

/**
 * Variante vendible de un producto (p. ej. color/talla), con su propio SKU canónico y atributos.
 *
 * Es un value object que vive DENTRO del agregado Product (no tiene identidad propia ni se consulta de
 * forma independiente en esta fase): por eso se persiste como JSON en la fila del producto, no en tabla
 * aparte. Los atributos son pares string→string (p. ej. {"color":"negro","talla":"M"}).
 */
final readonly class ProductVariant
{
    /**
     * @param array<string, string> $attributes
     */
    private function __construct(
        public Sku $sku,
        public string $name,
        public array $attributes,
    ) {}

    /**
     * @param array<string, string> $attributes
     */
    public static function of(Sku $sku, string $name, array $attributes = []): self
    {
        $name = trim($name);
        if ($name === '') {
            throw CatalogException::because('El nombre de la variante no puede estar vacío.');
        }

        return new self($sku, $name, $attributes);
    }

    /**
     * Reconstituye una variante desde su forma primitiva (columna JSON).
     *
     * @param array{sku: string, name: string, attributes?: array<string, string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Sku::of($data['sku']), $data['name'], $data['attributes'] ?? []);
    }

    /**
     * @return array{sku: string, name: string, attributes: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku->value,
            'name' => $this->name,
            'attributes' => $this->attributes,
        ];
    }
}
