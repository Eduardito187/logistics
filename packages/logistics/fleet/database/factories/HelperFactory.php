<?php

declare(strict_types=1);

namespace Logistics\Fleet\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de la fila `fleet_helpers`. Produce ayudantes de demo activos, con teléfono y un documento
 * (carné de salud) con vencimiento a futuro.
 *
 * @extends Factory<HelperModel>
 */
final class HelperFactory extends Factory
{
    protected $model = HelperModel::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'name' => $this->faker->name(),
            'phone' => $this->faker->numerify('7#######'),
            'documents' => [
                ['type' => 'health_card', 'expires_on' => $this->faker->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d')],
            ],
            'active' => true,
        ];
    }

    /**
     * Ayudante dado de baja (inactivo).
     */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    /**
     * Ayudante sin teléfono registrado.
     */
    public function withoutPhone(): self
    {
        return $this->state(fn (): array => ['phone' => null]);
    }
}
