<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Resources\ProductResource\Pages;

use Commerce\Catalog\Application\Command\DraftProduct\DraftProduct;
use Commerce\Catalog\Application\Command\SetProductPhysicalSpec\SetProductPhysicalSpec;
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
        $record = ProductRecord::query()->where('canonical_sku', $canonicalSku)->firstOrFail();

        // Medidas físicas opcionales (Fase 2.0): solo si se cargó al menos una.
        $spec = [
            'weight_kg' => self::toFloat($data['weight_kg'] ?? null),
            'length_cm' => self::toFloat($data['length_cm'] ?? null),
            'width_cm' => self::toFloat($data['width_cm'] ?? null),
            'height_cm' => self::toFloat($data['height_cm'] ?? null),
        ];
        if (array_filter($spec, static fn (?float $value): bool => $value !== null) !== []) {
            app(CommandBus::class)->dispatch(new SetProductPhysicalSpec(
                productId: (string) $record->getKey(),
                weightKg: $spec['weight_kg'],
                lengthCm: $spec['length_cm'],
                widthCm: $spec['width_cm'],
                heightCm: $spec['height_cm'],
            ));
            $record->refresh();
        }

        return $record;
    }

    private static function toFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
