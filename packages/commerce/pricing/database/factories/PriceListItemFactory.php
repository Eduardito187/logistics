<?php

declare(strict_types=1);

namespace Commerce\Pricing\Database\Factories;

use Commerce\Pricing\Domain\ValueObject\PriceListId;
use Commerce\Pricing\Infrastructure\Persistence\Eloquent\Model\PriceListItemRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fábrica de precios (ítems) de una lista para seeders de demo y pruebas Feature.
 *
 * @extends Factory<PriceListItemRecord>
 */
final class PriceListItemFactory extends Factory
{
    protected $model = PriceListItemRecord::class;

    public function definition(): array
    {
        return [
            'id' => PriceListId::generate()->toString(),
            'price_list_id' => PriceListId::generate()->toString(),
            'sku' => 'SKU-'.$this->faker->unique()->numerify('######'),
            'gross_amount_cents' => $this->faker->numberBetween(1000, 1000000),
            'currency' => 'BOB',
        ];
    }

    /**
     * Precio para una lista concreta.
     */
    public function forList(string $priceListId): self
    {
        return $this->state(fn (): array => ['price_list_id' => $priceListId]);
    }
}
