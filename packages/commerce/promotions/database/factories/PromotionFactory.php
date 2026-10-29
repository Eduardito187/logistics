<?php

declare(strict_types=1);

namespace Commerce\Promotions\Database\Factories;

use Commerce\Promotions\Infrastructure\Persistence\Eloquent\Model\PromotionRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de datos de prueba/demo para PromotionRecord.
 *
 * @extends Factory<PromotionRecord>
 */
final class PromotionFactory extends Factory
{
    /**
     * @var class-string<PromotionRecord>
     */
    protected $model = PromotionRecord::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'name' => ucwords((string) $this->faker->words(2, true)),
            'code' => null,
            'type' => 'percentage_off',
            'value' => 1000,
            'currency' => 'BOB',
            'conditions' => [
                'min_subtotal_cents' => 0,
                'channel' => null,
                'starts_at' => null,
                'ends_at' => null,
            ],
            'status' => 'draft',
            'version' => 1,
        ];
    }

    public function coupon(string $code): self
    {
        return $this->state(fn (array $attributes): array => ['code' => strtoupper($code)]);
    }

    public function active(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'active']);
    }

    public function expired(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'expired']);
    }
}
