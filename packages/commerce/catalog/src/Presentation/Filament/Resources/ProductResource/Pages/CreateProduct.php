<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Resources\ProductResource\Pages;

use Commerce\Catalog\Application\Command\DraftProduct\DraftProduct;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\ProductRecord;
use Commerce\Catalog\Presentation\Filament\Resources\ProductResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un producto desde el admin.
 *
 * No persiste con Eloquent: despacha el comando DraftProduct al bus CQRS (el dominio genera el id y deja
 * el producto en borrador). Como el comando no devuelve el id, se recupera el record recién creado por su
 * SKU canónico (único) para la redirección del admin.
 */
final class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $canonicalSku = (string) $data['canonical_sku'];

        $categoryId = isset($data['category_id']) && (string) $data['category_id'] !== ''
            ? (string) $data['category_id']
            : null;

        $description = isset($data['description']) && (string) $data['description'] !== ''
            ? (string) $data['description']
            : null;

        app(CommandBus::class)->dispatch(new DraftProduct(
            name: (string) $data['name'],
            canonicalSku: $canonicalSku,
            sellerId: null,
            description: $description,
            categoryId: $categoryId,
        ));

        // El SKU canónico es único: identifica el producto recién creado para la redirección.
        return ProductRecord::query()->where('canonical_sku', $canonicalSku)->firstOrFail();
    }
}
