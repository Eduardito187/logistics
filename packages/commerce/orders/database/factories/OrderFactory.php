<?php

declare(strict_types=1);

namespace Commerce\Orders\Database\Factories;

use Commerce\Orders\Infrastructure\Persistence\Eloquent\Model\OrderRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de datos de prueba/demo para OrderRecord.
 *
 * @extends Factory<OrderRecord>
 */
final class OrderFactory extends Factory
{
    /**
     * @var class-string<OrderRecord>
     */
    protected $model = OrderRecord::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'customer_id' => null,
            'status' => 'pending',
            'currency' => 'BOB',
            'shipment_requested' => false,
            'ship_to' => [
                'customer_name' => $this->faker->name(),
                'address_text' => $this->faker->address(),
                'reference' => null,
                'phone' => null,
                'email' => null,
            ],
            'lines' => [
                [
                    'sku' => strtoupper((string) $this->faker->bothify('SKU-####')),
                    'product_name' => ucwords(implode(' ', $this->faker->words(2))),
                    'unit_price_cents' => $this->faker->numberBetween(1000, 500000),
                    'quantity' => $this->faker->numberBetween(1, 3),
                ],
            ],
        ];
    }

    public function paid(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'paid']);
    }
}
