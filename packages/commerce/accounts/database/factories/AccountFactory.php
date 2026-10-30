<?php

declare(strict_types=1);

namespace Commerce\Accounts\Database\Factories;

use Commerce\Accounts\Infrastructure\Persistence\Eloquent\Model\AccountRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de datos de prueba/demo para AccountRecord.
 *
 * @extends Factory<AccountRecord>
 */
final class AccountFactory extends Factory
{
    /**
     * @var class-string<AccountRecord>
     */
    protected $model = AccountRecord::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'type' => 'person',
            'user_id' => Uuid::generate()->value,
            'display_name' => $this->faker->name(),
            'tax_id' => null,
            'status' => 'active',
            'addresses' => [],
        ];
    }

    public function company(): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'company',
            'display_name' => $this->faker->company(),
            'tax_id' => (string) $this->faker->numerify('##########'),
        ]);
    }

    public function sellerPerson(): self
    {
        return $this->state(fn (array $attributes): array => ['type' => 'seller_person']);
    }

    public function suspended(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'suspended']);
    }
}
