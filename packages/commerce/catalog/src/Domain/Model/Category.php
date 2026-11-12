<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\Model;

use Commerce\Catalog\Domain\Event\CategoryCreated;
use Commerce\Catalog\Domain\Exception\CatalogException;
use Commerce\Catalog\Domain\ValueObject\CategoryId;
use Commerce\Catalog\Domain\ValueObject\PhysicalSpec;
use Commerce\Catalog\Domain\ValueObject\Slug;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Categoría del catálogo: un árbol simple (cada categoría puede tener una categoría padre).
 *
 * Agregado pequeño e independiente del producto: el producto solo referencia su `CategoryId`.
 *
 * Guarda además las medidas físicas POR DEFECTO de sus productos ({@see PhysicalSpec}): es el segundo
 * escalón de la cascada producto → categoría → default global cuando un producto no tiene medidas
 * (Roadmap 02, Fase 2.1). Son opcionales y parciales; nunca bloquean nada.
 */
final class Category extends AggregateRoot
{
    private function __construct(
        private readonly CategoryId $id,
        private string $name,
        private Slug $slug,
        private ?CategoryId $parentId,
        private PhysicalSpec $physicalDefaults,
    ) {}

    public static function create(
        CategoryId $id,
        string $name,
        Slug $slug,
        ?CategoryId $parentId = null,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw CatalogException::because('El nombre de la categoría no puede estar vacío.');
        }
        if ($parentId !== null && $parentId->equals($id)) {
            throw CatalogException::because('Una categoría no puede ser su propia categoría padre.');
        }

        $category = new self($id, $name, $slug, $parentId, PhysicalSpec::empty());
        $category->recordEvent(new CategoryCreated(
            $id->toString(),
            $name,
            $slug->value,
            $parentId?->toString(),
        ));

        return $category;
    }

    public static function reconstitute(
        CategoryId $id,
        string $name,
        Slug $slug,
        ?CategoryId $parentId,
        ?PhysicalSpec $physicalDefaults = null,
    ): self {
        return new self($id, $name, $slug, $parentId, $physicalDefaults ?? PhysicalSpec::empty());
    }

    /**
     * Define (reemplaza) las medidas por defecto de los productos de esta categoría. Ficha vacía o
     * parcial es válida.
     */
    public function definePhysicalDefaults(PhysicalSpec $defaults): void
    {
        $this->physicalDefaults = $defaults;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw CatalogException::because('El nombre de la categoría no puede estar vacío.');
        }
        $this->name = $name;
    }

    public function id(): CategoryId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function parentId(): ?CategoryId
    {
        return $this->parentId;
    }

    public function physicalDefaults(): PhysicalSpec
    {
        return $this->physicalDefaults;
    }
}
