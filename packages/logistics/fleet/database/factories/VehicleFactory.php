<?php

declare(strict_types=1);

namespace Logistics\Fleet\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de la fila `fleet_vehicles`. Produce vehículos de demo con placa boliviana verosímil,
 * capacidad coherente (kg/m³) y un par de documentos con vencimiento (SOAT e inspección técnica).
 *
 * El id es UUID v7 (ordenable), igual que el que genera el dominio.
 *
 * @extends Factory<VehicleModel>
 */
final class VehicleFactory extends Factory
{
    protected $model = VehicleModel::class;

    public function definition(): array
    {
        $kg = $this->faker->numberBetween(1500, 12000);

        return [
            'id' => Uuid::generate()->value,
            'plate' => strtoupper($this->faker->bothify('###?##')),
            'capacity_kg' => $kg,
            'capacity_m3' => $this->faker->randomFloat(3, 5, 45),
            'home_base_node_id' => Uuid::generate()->value,
            'status' => VehicleStatus::Available->value,
            'documents' => [
                ['type' => 'soat', 'expires_on' => $this->faker->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d')],
                ['type' => 'technical_inspection', 'expires_on' => $this->faker->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d')],
            ],
        ];
    }

    /**
     * Vehículo en mantenimiento (fuera de servicio).
     */
    public function maintenance(): self
    {
        return $this->state(fn (): array => ['status' => VehicleStatus::Maintenance->value]);
    }

    /**
     * Fija el nodo base (útil para agrupar la flota de demo en un mismo CD).
     */
    public function homeBase(string $nodeId): self
    {
        return $this->state(fn (): array => ['home_base_node_id' => $nodeId]);
    }

    /**
     * Vehículo con un documento ya vencido (para probar las alertas de vencimiento).
     */
    public function withExpiredDocument(): self
    {
        return $this->state(fn (): array => [
            'documents' => [
                ['type' => 'soat', 'expires_on' => $this->faker->dateTimeBetween('-3 months', '-1 day')->format('Y-m-d')],
            ],
        ]);
    }
}
