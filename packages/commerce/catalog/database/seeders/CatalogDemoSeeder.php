<?php

declare(strict_types=1);

namespace Commerce\Catalog\Database\Seeders;

use Commerce\Catalog\Application\Command\CreateCategory\CreateCategory;
use Commerce\Catalog\Application\Command\DraftProduct\DraftProduct;
use Commerce\Catalog\Application\Command\PublishProduct\PublishProduct;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryRecord;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\ProductRecord;
use Illuminate\Database\Seeder;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Datos demo del catálogo. Idempotente y UUID-estable: cada entidad se crea solo si no existe (por su
 * clave natural: slug de categoría / SKU canónico de producto). No regenera IDs en re-corridas.
 *
 * Usa el CommandBus (flujo transaccional del dominio + outbox), nunca escribe los Records directo.
 */
final class CatalogDemoSeeder extends Seeder
{
    public function run(): void
    {
        /** @var CommandBus $bus */
        $bus = app(CommandBus::class);

        $categories = [
            ['name' => 'Electrodomésticos', 'slug' => 'electrodomesticos'],
            ['name' => 'Tecnología', 'slug' => 'tecnologia'],
        ];
        foreach ($categories as $category) {
            if (!CategoryRecord::query()->where('slug', $category['slug'])->exists()) {
                $bus->dispatch(new CreateCategory($category['name'], $category['slug']));
            }
        }

        $electro = CategoryRecord::query()->where('slug', 'electrodomesticos')->first();
        $tech = CategoryRecord::query()->where('slug', 'tecnologia')->first();

        $products = [
            ['name' => 'Heladera No Frost 300L', 'sku' => 'SKU-RD-300', 'category' => $electro?->id, 'description' => 'Heladera No Frost de 300 litros.'],
            ['name' => 'Televisor LED 55"', 'sku' => 'SKU-TV-55', 'category' => $tech?->id, 'description' => 'Televisor LED 55 pulgadas 4K.'],
            ['name' => 'Lavadora Automática 12Kg', 'sku' => 'SKU-WM-12', 'category' => $electro?->id, 'description' => 'Lavadora automática de 12 kilos.'],
        ];
        foreach ($products as $product) {
            if (ProductRecord::query()->where('canonical_sku', $product['sku'])->exists()) {
                continue;
            }
            $bus->dispatch(new DraftProduct(
                name: $product['name'],
                canonicalSku: $product['sku'],
                description: $product['description'],
                categoryId: $product['category'],
            ));
            $record = ProductRecord::query()->where('canonical_sku', $product['sku'])->first();
            if ($record instanceof ProductRecord) {
                $bus->dispatch(new PublishProduct($record->id));
            }
        }
    }
}
