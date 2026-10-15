<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\NodeReceptionUnitRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de bultos recibidos en un nodo para tests y seeders. Cada bulto trae su GPS, dispositivo/usuario,
 * hora y una clave de idempotencia única (respaldo de la idempotencia offline a nivel de base).
 *
 * @extends Factory<NodeReceptionUnitRecord>
 */
final class NodeReceptionUnitRecordFactory extends Factory
{
    protected $model = NodeReceptionUnitRecord::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'node_reception_id' => Uuid::generate()->value,
            'unit_id' => 'U-'.$this->faker->bothify('########'),
            // La Paz y alrededores: coordenadas realistas para la demo.
            'lat' => $this->faker->randomFloat(7, -16.6, -16.4),
            'lng' => $this->faker->randomFloat(7, -68.2, -68.0),
            'device_id' => 'device-'.$this->faker->bothify('####'),
            'user_id' => 'user-'.$this->faker->bothify('####'),
            'occurred_at' => $this->faker->dateTimeThisMonth()->format(DATE_ATOM),
            'idempotency_key' => Uuid::generate()->value,
        ];
    }

    /**
     * Bulto perteneciente a una recepción dada.
     */
    public function forReception(string $nodeReceptionId): self
    {
        return $this->state(fn (): array => ['node_reception_id' => $nodeReceptionId]);
    }
}
