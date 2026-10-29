<?php

declare(strict_types=1);

namespace Commerce\Cart\Database\Factories;

use Commerce\Cart\Infrastructure\Persistence\Eloquent\Model\CartRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de datos de prueba/demo para CartRecord.
 *
 * @extends Factory<CartRecord>
 */
final class CartFactory extends Factory
{
    /**
     * @var class-string<CartRecord>
     */
    protected $model = CartRecord::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'customer_id' => null,
            'session_id' => (string) $this->faker->uuid(),
            'status' => 'open',
            'currency' => 'BOB',
            'lines' => [],
        ];
    }

    public function forCustomer(string $customerId): self
    {
        return $this->state(fn (array $attributes): array => ['customer_id' => $customerId]);
    }

    public function converted(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'converted']);
    }

    public function abandoned(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'abandoned']);
    }
}
