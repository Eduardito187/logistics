<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\ReadModel;

use Commerce\Catalog\Domain\Model\Product;
use Commerce\Catalog\Domain\ValueObject\MediaAsset;
use Commerce\Catalog\Domain\ValueObject\ProductVariant;

/**
 * Proyección de lectura de un producto para listados, API y storefront. Primitivos: no expone dominio.
 */
final readonly class ProductView
{
    /**
     * @param list<array{sku: string, name: string, attributes: array<string, string>}> $variants
     * @param list<array{path: string, alt: string, position: int}>                     $media
     */
    private function __construct(
        public string $id,
        public ?string $sellerId,
        public ?string $categoryId,
        public string $name,
        public ?string $description,
        public string $canonicalSku,
        public string $status,
        public array $variants,
        public array $media,
    ) {}

    public static function fromDomain(Product $product): self
    {
        return new self(
            $product->id()->toString(),
            $product->sellerId()?->value,
            $product->categoryId()?->toString(),
            $product->name(),
            $product->description(),
            $product->canonicalSku()->value,
            $product->status()->value,
            array_map(static fn (ProductVariant $variant): array => $variant->toArray(), $product->variants()),
            array_map(static fn (MediaAsset $media): array => $media->toArray(), $product->media()),
        );
    }

    /**
     * @return array{id: string, seller_id: ?string, category_id: ?string, name: string, description: ?string, canonical_sku: string, status: string, variants: list<array{sku: string, name: string, attributes: array<string, string>}>, media: list<array{path: string, alt: string, position: int}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'seller_id' => $this->sellerId,
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'description' => $this->description,
            'canonical_sku' => $this->canonicalSku,
            'status' => $this->status,
            'variants' => $this->variants,
            'media' => $this->media,
        ];
    }
}
