<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\GeofenceRecord;

/**
 * Fábrica de geocercas para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce
 * el repositorio en producción. El polígono por defecto es un cuadrado pequeño alrededor de un punto
 * en La Paz; no pasa por el dominio a propósito (una fábrica arma filas directamente).
 *
 * @extends Factory<GeofenceRecord>
 */
final class GeofenceFactory extends Factory
{
    protected $model = GeofenceRecord::class;

    public function definition(): array
    {
        $lat = $this->faker->latitude(-16.6, -16.4);
        $lng = $this->faker->longitude(-68.2, -68.0);
        $delta = 0.01;

        return [
            'id' => GeofenceId::generate()->toString(),
            'name' => $this->faker->unique()->streetName(),
            'type' => $this->faker->randomElement(array_map(
                static fn (GeofenceType $type): string => $type->value,
                GeofenceType::cases(),
            )),
            'polygon' => [
                ['lat' => $lat - $delta, 'lng' => $lng - $delta],
                ['lat' => $lat - $delta, 'lng' => $lng + $delta],
                ['lat' => $lat + $delta, 'lng' => $lng + $delta],
                ['lat' => $lat + $delta, 'lng' => $lng - $delta],
            ],
            'active' => true,
            'node_ref' => null,
        ];
    }

    /**
     * Geocerca desactivada.
     */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    /**
     * Geocerca de un tipo concreto.
     */
    public function ofType(GeofenceType $type): self
    {
        return $this->state(fn (): array => ['type' => $type->value]);
    }
}
