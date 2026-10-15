<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Execution\Domain\ValueObject\ScanType;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\ScanEventRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de escaneos de terreno para tests y seeders. Cada escaneo trae su GPS, dispositivo/usuario,
 * hora y una clave de idempotencia única (respaldo de la idempotencia offline a nivel de base).
 *
 * @extends Factory<ScanEventRecord>
 */
final class ScanEventRecordFactory extends Factory
{
    protected $model = ScanEventRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = array_map(fn (ScanType $type): string => $type->value, ScanType::cases());

        return [
            'id' => Uuid::generate()->value,
            'delivery_id' => Uuid::generate()->value,
            'type' => $this->faker->randomElement($types),
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
     * Escaneo de un tipo concreto (preparación, carga, descarga, prueba de entrega, recepción).
     */
    public function ofType(ScanType $type): self
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }

    /**
     * Escaneo perteneciente a una entrega dada.
     */
    public function forDelivery(string $deliveryId): self
    {
        return $this->state(fn (): array => ['delivery_id' => $deliveryId]);
    }
}
