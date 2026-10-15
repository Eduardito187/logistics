<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Telemetry\Domain\ValueObject\StreetSegmentId;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\StreetStatsRecord;

/**
 * Fábrica de estadística de calles para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce el
 * repositorio en producción. No pasa por el dominio a propósito: una fábrica arma filas directamente.
 *
 * @extends Factory<StreetStatsRecord>
 */
final class StreetStatsFactory extends Factory
{
    protected $model = StreetStatsRecord::class;

    public function definition(): array
    {
        $samples = $this->faker->numberBetween(3, 50);
        $avg = $this->faker->randomFloat(2, 5, 60);
        $lat = $this->faker->randomFloat(7, -17.80, -17.30);
        $lng = $this->faker->randomFloat(7, -66.25, -66.10);

        $histogram = array_fill(0, 24, 0);
        $histogram[$this->faker->numberBetween(6, 20)] = $samples;

        return [
            'id' => StreetSegmentId::generate()->toString(),
            'segment_key' => sha1($this->faker->unique()->numerify('########').'-'.$this->faker->numerify('########')),
            'name' => $this->faker->optional()->streetName(),
            'geometry' => [
                ['lat' => $lat, 'lng' => $lng],
                ['lat' => $lat + 0.0012, 'lng' => $lng + 0.0012],
            ],
            'sample_count' => $samples,
            'avg_speed_kph' => $avg,
            'min_speed_kph' => max(0.0, $avg - $this->faker->randomFloat(2, 1, 5)),
            'total_seconds' => $samples * $this->faker->numberBetween(10, 40),
            'last_sampled_at' => $this->faker->dateTimeThisMonth(),
            'hour_histogram' => $histogram,
        ];
    }

    /**
     * Segmento congestionado (velocidad promedio baja).
     */
    public function congested(): self
    {
        return $this->state(fn (): array => [
            'avg_speed_kph' => $this->faker->randomFloat(2, 1, 9),
            'min_speed_kph' => $this->faker->randomFloat(2, 0, 3),
        ]);
    }
}
