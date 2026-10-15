<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Telemetry\Domain\ValueObject\AlertId;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\AlertRecord;

/**
 * Fábrica de alertas para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) y el vehicle_id como UUID opaco (el
 * contexto referencia a los vehículos de fleet por id como texto). Las fechas salen en ISO 8601 (ATOM),
 * como las persiste el mapper.
 *
 * @extends Factory<AlertRecord>
 */
final class AlertFactory extends Factory
{
    protected $model = AlertRecord::class;

    public function definition(): array
    {
        return [
            'id' => AlertId::generate()->toString(),
            'vehicle_id' => $this->faker->uuid(),
            'driver_id' => null,
            'type' => $this->faker->randomElement(array_map(
                static fn (AlertType $type): string => $type->value,
                AlertType::cases(),
            )),
            'severity' => $this->faker->randomElement(array_map(
                static fn (AlertSeverity $severity): string => $severity->value,
                AlertSeverity::cases(),
            )),
            'lat' => $this->faker->latitude(-16.6, -16.4),
            'lng' => $this->faker->longitude(-68.2, -68.0),
            'detail' => [],
            'occurred_at' => (new DateTimeImmutable)->format(DateTimeImmutable::ATOM),
            'acknowledged_at' => null,
            'acknowledged_by' => null,
        ];
    }

    /**
     * Alerta ya reconocida por alguien de la operación.
     */
    public function acknowledged(string $by = 'ops'): self
    {
        return $this->state(fn (): array => [
            'acknowledged_at' => (new DateTimeImmutable)->format(DateTimeImmutable::ATOM),
            'acknowledged_by' => $by,
        ]);
    }

    /**
     * Alerta de un tipo y severidad concretos.
     */
    public function ofType(AlertType $type, AlertSeverity $severity): self
    {
        return $this->state(fn (): array => [
            'type' => $type->value,
            'severity' => $severity->value,
        ]);
    }
}
