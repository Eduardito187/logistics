<?php

declare(strict_types=1);

namespace Commerce\Catalog\Infrastructure\Persistence\Eloquent\Mapper;

use Commerce\Catalog\Domain\Model\Product;
use Commerce\Catalog\Domain\ValueObject\CategoryId;
use Commerce\Catalog\Domain\ValueObject\MediaAsset;
use Commerce\Catalog\Domain\ValueObject\ProductId;
use Commerce\Catalog\Domain\ValueObject\ProductStatus;
use Commerce\Catalog\Domain\ValueObject\ProductVariant;
use Commerce\Catalog\Domain\ValueObject\Sku;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\ProductRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Traduce entre el agregado Product (dominio) y su fila Eloquent. Único punto que conoce ambos lados.
 */
final class ProductMapper
{
    public function toDomain(ProductRecord $record): Product
    {
        return Product::reconstitute(
            ProductId::fromString($record->id),
            $record->seller_id !== null ? Uuid::fromString($record->seller_id) : null,
            $record->category_id !== null ? CategoryId::fromString($record->category_id) : null,
            $record->name,
            $record->description,
            Sku::of($record->canonical_sku),
            ProductStatus::from($record->status),
            array_values(array_map(
                static fn (array $variant): ProductVariant => ProductVariant::fromArray($variant),
                $record->variants,
            )),
            array_values(array_map(
                static fn (array $media): MediaAsset => MediaAsset::fromArray($media),
                $record->media,
            )),
        );
    }

    /**
     * @return array{id: string, seller_id: ?string, category_id: ?string, name: string, description: ?string, canonical_sku: string, status: string, variants: list<array{sku: string, name: string, attributes: array<string, string>}>, media: list<array{path: string, alt: string, position: int}>}
     */
    public function toRow(Product $product): array
    {
        return [
            'id' => $product->id()->toString(),
            'seller_id' => $product->sellerId()?->value,
            'category_id' => $product->categoryId()?->toString(),
            'name' => $product->name(),
            'description' => $product->description(),
            'canonical_sku' => $product->canonicalSku()->value,
            'status' => $product->status()->value,
            'variants' => array_values(array_map(
                static fn (ProductVariant $variant): array => $variant->toArray(),
                $product->variants(),
            )),
            'media' => array_values(array_map(
                static fn (MediaAsset $media): array => $media->toArray(),
                $product->media(),
            )),
        ];
    }
}
