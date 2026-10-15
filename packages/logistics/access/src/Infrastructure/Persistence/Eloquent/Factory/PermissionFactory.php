<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Access\Domain\ValueObject\PermissionId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\PermissionRecord;

/**
 * Fábrica de permisos para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce
 * el repositorio en producción. No pasa por el dominio a propósito: una fábrica es un detalle de
 * infraestructura que arma filas directamente.
 *
 * @extends Factory<PermissionRecord>
 */
final class PermissionFactory extends Factory
{
    protected $model = PermissionRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $group = $this->faker->unique()->word();
        $action = $this->faker->randomElement(['read', 'create', 'update', 'delete', 'manage']);

        return [
            'id' => PermissionId::generate()->toString(),
            'key' => $group.'.'.$action,
            'group' => $group,
            'action' => $action,
            'description' => null,
            'is_system' => false,
        ];
    }

    /**
     * Permiso de sistema (parte del catálogo base, no editable desde el admin).
     */
    public function system(): self
    {
        return $this->state(fn (): array => ['is_system' => true]);
    }
}
