<?php

declare(strict_types=1);

namespace Commerce\Catalog\Database\Factories;

use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\ProductRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de datos de prueba/demo para ProductRecord.
 *
 * @extends Factory<ProductRecord>
 */
final class ProductFactory extends Factory
{
    /**
     * @var class-string<ProductRecord>
     */
    protected $model = ProductRecord::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'seller_id' => null,
            'category_id' => null,
            'name' => ucwords(implode(' ', (array) $this->faker->words(3))),
            'description' => $this->faker->sentence(),
            'canonical_sku' => strtoupper((string) $this->faker->unique()->bothify('SKU-####-???')),
            'status' => 'draft',
            'variants' => [],
            'media' => [],
        ];
    }

    public function published(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'published']);
    }

    public function archived(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'archived']);
    }
}
