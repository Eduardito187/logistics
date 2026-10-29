<?php

declare(strict_types=1);

namespace Commerce\Catalog\Database\Factories;

use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de datos de prueba/demo para CategoryRecord.
 *
 * @extends Factory<CategoryRecord>
 */
final class CategoryFactory extends Factory
{
    /**
     * @var class-string<CategoryRecord>
     */
    protected $model = CategoryRecord::class;

    public function definition(): array
    {
        $name = ucwords((string) $this->faker->unique()->words(2, true));

        return [
            'id' => Uuid::generate()->value,
            'parent_id' => null,
            'name' => $name,
            'slug' => strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name)),
        ];
    }
}
