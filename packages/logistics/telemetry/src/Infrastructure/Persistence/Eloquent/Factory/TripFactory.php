<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Telemetry\Domain\ValueObject\TripId;
use Logistics\Telemetry\Domain\ValueObject\TripStatus;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\TripRecord;

/**
 * Fábrica de trips (tramos continuos de conducción) para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce el
 * repositorio en producción. No pasa por el dominio a propósito: una fábrica arma filas directamente.
 *
 * @extends Factory<TripRecord>
 */
final class TripFactory extends Factory
{
    protected $model = TripRecord::class;

    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeThisMonth();
        $durationS = $this->faker->numberBetween(300, 7200);
        $movingS = $this->faker->numberBetween(0, $durationS);
        $startLat = $this->faker->randomFloat(7, -17.80, -17.30);
        $startLng = $this->faker->randomFloat(7, -66.25, -66.10);

        return [
            'id' => TripId::generate()->toString(),
            'vehicle_id' => $this->faker->uuid(),
            'driver_id' => null,
            'route_id' => null,
            'status' => TripStatus::Completed->value,
            'started_at' => $startedAt,
            'ended_at' => (clone $startedAt)->modify("+{$durationS} seconds"),
            'start_lat' => $startLat,
            'start_lng' => $startLng,
            'end_lat' => $startLat + $this->faker->randomFloat(4, -0.05, 0.05),
            'end_lng' => $startLng + $this->faker->randomFloat(4, -0.05, 0.05),
            'distance_m' => $this->faker->randomFloat(2, 500, 40_000),
            'duration_s' => $durationS,
            'moving_s' => $movingS,
            'idle_s' => $durationS - $movingS,
            'max_speed_kph' => $this->faker->randomFloat(2, 20, 110),
            'ping_count' => $this->faker->numberBetween(2, 2000),
        ];
    }

    /**
     * Trip todavía activo: sin fin ni métricas de cierre.
     */
    public function active(): self
    {
        return $this->state(fn (): array => [
            'status' => TripStatus::Active->value,
            'ended_at' => null,
            'end_lat' => null,
            'end_lng' => null,
        ]);
    }
}
