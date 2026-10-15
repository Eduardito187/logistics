<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\CapacityBucketRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de buckets de cupo para seeders y tests.
 *
 * Genera filas de `capacity_buckets` con la misma forma que produce el dominio: id UUID v7, scope de
 * nodo (zona en null por defecto), fecha, franja, unidad y los decimales total/used formateados como
 * decimal(12,3). El consumo nace en cero salvo que un estado lo cambie, y nunca excede el total.
 *
 * @extends Factory<CapacityBucketRecord>
 */
final class CapacityBucketFactory extends Factory
{
    /**
     * @var class-string<CapacityBucketRecord>
     */
    protected $model = CapacityBucketRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = (float) $this->faker->numberBetween(20, 200);

        return [
            'id' => Uuid::generate()->value,
            // Scope por defecto: un nodo. La zona queda en null (el dominio exige exactamente uno).
            'node_id' => Uuid::generate()->value,
            'zone_id' => null,
            'slot_date' => $this->faker->dateTimeBetween('now', '+14 days')->format('Y-m-d'),
            'time_slot' => $this->faker->randomElement(['08:00-12:00', '12:00-16:00', '16:00-20:00']),
            'unit' => $this->faker->randomElement(array_column(CapacityUnit::cases(), 'value')),
            'total' => number_format($total, 3, '.', ''),
            'used' => number_format(0, 3, '.', ''),
        ];
    }

    /**
     * Ubica el cupo en un nodo concreto (y limpia la zona).
     */
    public function forNode(string $nodeId): self
    {
        return $this->state(fn (): array => ['node_id' => $nodeId, 'zone_id' => null]);
    }

    /**
     * Ubica el cupo en una zona concreta (y limpia el nodo).
     */
    public function forZone(string $zoneId): self
    {
        return $this->state(fn (): array => ['node_id' => null, 'zone_id' => $zoneId]);
    }

    /**
     * Fija fecha (Y-m-d) y franja (HH:MM-HH:MM) del cupo.
     */
    public function onSlot(string $date, string $timeSlot): self
    {
        return $this->state(fn (): array => ['slot_date' => $date, 'time_slot' => $timeSlot]);
    }

    /**
     * Fija la unidad de medida del cupo.
     */
    public function withUnit(CapacityUnit $unit): self
    {
        return $this->state(fn (): array => ['unit' => $unit->value]);
    }

    /**
     * Fija total y consumido (consumido acotado al total para no sobrevender).
     */
    public function withCapacity(float $total, float $used = 0.0): self
    {
        return $this->state(fn (): array => [
            'total' => number_format(max(0.0, $total), 3, '.', ''),
            'used' => number_format(min(max(0.0, $used), max(0.0, $total)), 3, '.', ''),
        ]);
    }
}
