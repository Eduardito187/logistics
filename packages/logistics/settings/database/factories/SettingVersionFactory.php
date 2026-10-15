<?php

declare(strict_types=1);

namespace Logistics\Settings\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Settings\Infrastructure\Persistence\Eloquent\SettingVersionRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de versiones de configuración para seeders y tests.
 *
 * Genera filas de `settings_versions` con la misma forma que produce el dominio: id UUID v7, clave
 * namespaced, número de versión, valor como arreglo (columna jsonb) y marcas de auditoría. Las filas
 * son inmutables, así que la factory solo sirve para crearlas, nunca para actualizarlas.
 *
 * @extends Factory<SettingVersionRecord>
 */
final class SettingVersionFactory extends Factory
{
    /**
     * @var class-string<SettingVersionRecord>
     */
    protected $model = SettingVersionRecord::class;

    public function definition(): array
    {
        $now = now();

        return [
            'id' => Uuid::generate()->value,
            'key' => $this->faker->randomElement([
                'coverage.default_service_days',
                'cutoff.scz_evening',
                'capacity.default_bucket',
                'carriers.base_tariff',
            ]),
            'version' => 1,
            'value' => ['enabled' => true, 'threshold' => $this->faker->numberBetween(1, 10)],
            'effective_from' => $now->toIso8601String(),
            'created_at' => $now->toIso8601String(),
            'created_by' => $this->faker->safeEmail(),
        ];
    }

    /**
     * Fija la clave de la versión generada.
     */
    public function forKey(string $key): self
    {
        return $this->state(fn (): array => ['key' => $key]);
    }

    /**
     * Fija el número de versión (para construir historiales consistentes en los tests).
     */
    public function version(int $version): self
    {
        return $this->state(fn (): array => ['version' => $version]);
    }

    /**
     * Fija el valor (arreglo primitivo) de la versión generada.
     *
     * @param array<string, mixed> $value
     */
    public function withValue(array $value): self
    {
        return $this->state(fn (): array => ['value' => $value]);
    }
}
