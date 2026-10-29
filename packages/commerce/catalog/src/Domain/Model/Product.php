<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\Model;

use Commerce\Catalog\Domain\Event\ProductArchived;
use Commerce\Catalog\Domain\Event\ProductDrafted;
use Commerce\Catalog\Domain\Event\ProductPublished;
use Commerce\Catalog\Domain\Exception\CatalogException;
use Commerce\Catalog\Domain\ValueObject\CategoryId;
use Commerce\Catalog\Domain\ValueObject\MediaAsset;
use Commerce\Catalog\Domain\ValueObject\ProductStatus;
use Commerce\Catalog\Domain\ValueObject\ProductVariant;
use Commerce\Catalog\Domain\ValueObject\Sku;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Producto del catálogo / PIM (ADR 018, Fase 0 de comercio).
 *
 * Custodia su identidad, dueño (seller; null = producto propio OWNED de Dismac, coherente con ADR 017),
 * categoría, nombre, descripción, SKU canónico, estado de publicación y sus variantes/medios. Las
 * variantes y los medios son value objects que viven DENTRO del agregado (se persisten como JSON en la
 * fila del producto), no entidades con identidad propia: el producto es la frontera de consistencia.
 *
 * El precio NO vive acá: lo resuelve el contexto `pricing` (ADR 018). El stock/ATP lo resuelve
 * `inventory` por un puerto de lectura; el SKU canónico es el puente 1:1.
 */
final class Product extends AggregateRoot
{
    /**
     * @param list<ProductVariant> $variants
     * @param list<MediaAsset>     $media
     */
    private function __construct(
        private readonly ProductId $id,
        private readonly ?Uuid $sellerId,
        private ?CategoryId $categoryId,
        private string $name,
        private ?string $description,
        private readonly Sku $canonicalSku,
        private ProductStatus $status,
        private array $variants,
        private array $media,
    ) {}

    /**
     * Da de alta un producto nuevo en borrador. El dueño (seller) es opcional: null = producto propio.
     */
    public static function draft(
        ProductId $id,
        ?Uuid $sellerId,
        string $name,
        Sku $canonicalSku,
        ?string $description = null,
        ?CategoryId $categoryId = null,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw CatalogException::because('El nombre del producto no puede estar vacío.');
        }
        $description = $description !== null ? trim($description) : null;

        $product = new self(
            $id,
            $sellerId,
            $categoryId,
            $name,
            $description === '' ? null : $description,
            $canonicalSku,
            ProductStatus::Draft,
            [],
            [],
        );
        $product->recordEvent(new ProductDrafted(
            $id->toString(),
            $sellerId?->value,
            $name,
            $canonicalSku->value,
        ));

        return $product;
    }

    /**
     * Reconstituye un producto desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<ProductVariant> $variants
     * @param list<MediaAsset>     $media
     */
    public static function reconstitute(
        ProductId $id,
        ?Uuid $sellerId,
        ?CategoryId $categoryId,
        string $name,
        ?string $description,
        Sku $canonicalSku,
        ProductStatus $status,
        array $variants,
        array $media,
    ): self {
        return new self(
            $id,
            $sellerId,
            $categoryId,
            $name,
            $description,
            $canonicalSku,
            $status,
            $variants,
            $media,
        );
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw CatalogException::because('El nombre del producto no puede estar vacío.');
        }
        $this->name = $name;
    }

    public function describe(?string $description): void
    {
        $description = $description !== null ? trim($description) : null;
        $this->description = $description === '' ? null : $description;
    }

    public function categorize(?CategoryId $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    /**
     * Agrega una variante. El SKU de la variante es único dentro del producto.
     */
    public function addVariant(ProductVariant $variant): void
    {
        foreach ($this->variants as $existing) {
            if ($existing->sku->equals($variant->sku)) {
                throw CatalogException::because(
                    "El producto ya tiene una variante con el SKU {$variant->sku->value}.",
                );
            }
        }
        $this->variants[] = $variant;
    }

    public function removeVariant(Sku $sku): void
    {
        $this->variants = array_values(array_filter(
            $this->variants,
            static fn (ProductVariant $variant): bool => !$variant->sku->equals($sku),
        ));
    }

    public function addMedia(MediaAsset $media): void
    {
        $this->media[] = $media;
    }

    /**
     * Publica el producto (idempotente: si ya está publicado, no hace nada ni graba evento).
     */
    public function publish(): void
    {
        if ($this->status === ProductStatus::Published) {
            return;
        }
        $this->status = ProductStatus::Published;
        $this->recordEvent(new ProductPublished(
            $this->id->toString(),
            $this->name,
            $this->canonicalSku->value,
            $this->categoryId?->toString(),
            array_map(static fn (ProductVariant $variant): string => $variant->sku->value, $this->variants),
        ));
    }

    /**
     * Archiva el producto (idempotente).
     */
    public function archive(): void
    {
        if ($this->status === ProductStatus::Archived) {
            return;
        }
        $this->status = ProductStatus::Archived;
        $this->recordEvent(new ProductArchived($this->id->toString()));
    }

    public function id(): ProductId
    {
        return $this->id;
    }

    public function sellerId(): ?Uuid
    {
        return $this->sellerId;
    }

    public function categoryId(): ?CategoryId
    {
        return $this->categoryId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function canonicalSku(): Sku
    {
        return $this->canonicalSku;
    }

    public function status(): ProductStatus
    {
        return $this->status;
    }

    /**
     * @return list<ProductVariant>
     */
    public function variants(): array
    {
        return $this->variants;
    }

    /**
     * @return list<MediaAsset>
     */
    public function media(): array
    {
        return $this->media;
    }
}
