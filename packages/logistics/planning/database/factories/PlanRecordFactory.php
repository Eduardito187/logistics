<?php

declare(strict_types=1);

namespace Logistics\Planning\Database\Factories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlanStatus;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de la fila `planning_plans`. Genera planes de demo/test con id UUID v7 del dominio y
 * vehículo referenciado por uuid string (planificación no conoce el contexto de flota).
 *
 * El estado por defecto es Draft; usar proposed()/published() para los otros puntos del ciclo de vida.
 * Las paradas se crean con PlannedStopRecordFactory o con el estado withStops().
 *
 * @extends Factory<PlanRecord>
 */
final class PlanRecordFactory extends Factory
{
    /**
     * @var class-string<PlanRecord>
     */
    protected $model = PlanRecord::class;

    public function definition(): array
    {
        return [
            'id' => PlanId::generate()->toString(),
            'plan_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'vehicle_id' => Uuid::generate()->value,
            'status' => PlanStatus::Draft->value,
        ];
    }

    /**
     * Plan ya propuesto por el motor de rutas (con secuencia, ETA y carga en sus paradas).
     */
    public function proposed(): self
    {
        return $this->state(fn (): array => ['status' => PlanStatus::Proposed->value]);
    }

    /**
     * Plan publicado y disponible para la ejecución.
     */
    public function published(): self
    {
        return $this->state(fn (): array => ['status' => PlanStatus::Published->value]);
    }

    /**
     * Fija el día del plan.
     */
    public function onDate(string $date): self
    {
        return $this->state(fn (): array => ['plan_date' => $date]);
    }

    /**
     * Agrega N paradas secuenciales a este plan tras crearlo.
     */
    public function withStops(int $count = 3): self
    {
        return $this->afterCreating(function (PlanRecord $plan) use ($count): void {
            for ($sequence = 1; $sequence <= $count; $sequence++) {
                PlannedStopRecordFactory::new()
                    ->scheduled($sequence)
                    ->create([
                        'plan_id' => $plan->id,
                        'sequence' => $sequence,
                    ]);
            }
        });
    }
}
