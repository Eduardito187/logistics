<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Factory de una línea de envío. Por defecto crea también su envío padre; si ya se tiene, se pasa
 * `shipment_id` en el state. Ids UUID v7.
 *
 * @extends Factory<ShipmentLineRecord>
 */
final class ShipmentLineRecordFactory extends Factory
{
    protected $model = ShipmentLineRecord::class;

    public function definition(): array
    {
        // `words(2, true)` devuelve siempre un string; el phpdoc de Faker lo tipa como array|string.
        /** @var string $productName */
        $productName = $this->faker->words(2, true);

        return [
            'id' => Uuid::generate()->value,
            'shipment_id' => ShipmentRecord::factory(),
            'sku' => strtoupper($this->faker->bothify('SKU-####??')),
            'product_name' => ucfirst($productName),
            'product_image_url' => 'https://picsum.photos/seed/'.$this->faker->bothify('????##').'/200/200',
            'unit_price' => $this->faker->randomFloat(2, 10, 5000),
            'unit_price_currency' => 'BOB',
            'quantity' => $this->faker->numberBetween(1, 5),
        ];
    }

    /**
     * Línea sin imagen ni precio, para probar la ingesta mínima (fuentes que no mandan presentación).
     */
    public function withoutPresentation(): self
    {
        return $this->state(fn (): array => [
            'product_image_url' => null,
            'unit_price' => null,
            'unit_price_currency' => null,
        ]);
    }
}
