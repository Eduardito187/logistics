<?php

declare(strict_types=1);

namespace Logistics\Promise\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Promise\Infrastructure\Persistence\Eloquent\PromiseRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de la fila `promises`. Produce promesas de demo coherentes con el ciclo de vida del agregado
 * (QUOTED → RESERVED → CONFIRMED | EXPIRED): el estado por defecto es QUOTED (sin reserva), y los
 * estados con reserva vigente (RESERVED) traen `reserved_until` a futuro, mientras que CONFIRMED y
 * EXPIRED ya no sostienen cupo.
 *
 * El id es UUID v7 (ordenable), igual que el que genera el dominio, para mantener índices compactos.
 *
 * @extends Factory<PromiseRecord>
 */
final class PromiseFactory extends Factory
{
    protected $model = PromiseRecord::class;

    /**
     * Estado base: una promesa recién cotizada, sin cupo reservado.
     */
    public function definition(): array
    {
        // Franja de entrega entre mañana y dos semanas hacia adelante (demo verosímil).
        $slotDate = $this->faker->dateTimeBetween('+1 day', '+14 days');

        return [
            'id' => Uuid::generate()->value,
            'shipment_ref' => 'SHIP-'.$this->faker->numberBetween(260000000, 260999999),
            'zone_id' => Uuid::generate()->value,
            'slot_date' => $slotDate->format('Y-m-d'),
            'time_slot' => $this->faker->randomElement(['AM', 'PM']),
            'status' => 'QUOTED',
            'reserved_until' => null,
        ];
    }

    /**
     * Promesa con cupo reservado: estado RESERVED y ventana de reserva vigente (a 30 minutos).
     */
    public function reserved(): self
    {
        return $this->state(fn (): array => [
            'status' => 'RESERVED',
            'reserved_until' => now()->addMinutes(30)->toIso8601String(),
        ]);
    }

    /**
     * Promesa confirmada en firme: estado CONFIRMED, ya sin ventana de reserva activa.
     */
    public function confirmed(): self
    {
        return $this->state(fn (): array => [
            'status' => 'CONFIRMED',
            'reserved_until' => null,
        ]);
    }

    /**
     * Promesa cuya reserva venció: estado EXPIRED, cupo liberado.
     */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'status' => 'EXPIRED',
            'reserved_until' => now()->subMinutes(15)->toIso8601String(),
        ]);
    }

    /**
     * Fija la zona (útil para agrupar promesas de demo en una misma zona de cobertura).
     */
    public function forZone(string $zoneId): self
    {
        return $this->state(fn (): array => [
            'zone_id' => $zoneId,
        ]);
    }
}
