<?php

declare(strict_types=1);

namespace Logistics\Returns\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Model\ReturnRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de devoluciones para seeders de demo y pruebas Feature. Genera el id como UUID v7 real (id
 * tipado del dominio) para coincidir con lo que produce el repositorio en producción.
 *
 * @extends Factory<ReturnRecord>
 */
final class ReturnRecordFactory extends Factory
{
    protected $model = ReturnRecord::class;

    public function definition(): array
    {
        return [
            'id' => ReturnId::generate()->toString(),
            'original_reference' => 'SHP-'.$this->faker->numberBetween(1000, 9999),
            'owner_party_id' => null,
            'warehouse_id' => Uuid::generate()->value,
            'fulfillment_mode' => 'owned',
            'kind' => 'customer_return',
            'disposition' => null,
            'status' => 'initiated',
        ];
    }

    /**
     * Devolución de un seller (marketplace FBD): dueño no null y modo `fbd`.
     */
    public function forSeller(string $ownerPartyId): self
    {
        return $this->state(fn (): array => [
            'owner_party_id' => $ownerPartyId,
            'fulfillment_mode' => 'fbd',
        ]);
    }
}
