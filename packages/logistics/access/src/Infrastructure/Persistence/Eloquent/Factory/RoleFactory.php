<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\RoleRecord;

/**
 * Fábrica de roles para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce
 * el repositorio en producción. No pasa por el dominio a propósito: una fábrica es un detalle de
 * infraestructura que arma filas directamente.
 *
 * @extends Factory<RoleRecord>
 */
final class RoleFactory extends Factory
{
    protected $model = RoleRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => RoleId::generate()->toString(),
            'key' => $this->faker->unique()->slug(2),
            'name' => $this->faker->unique()->jobTitle(),
            'description' => null,
            'is_system' => false,
            'permission_keys' => [],
        ];
    }

    /**
     * Rol de sistema (parte del catálogo base, no eliminable).
     */
    public function system(): self
    {
        return $this->state(fn (): array => ['is_system' => true]);
    }

    /**
     * Rol con un conjunto concreto de permisos.
     *
     * @param list<string> $permissionKeys
     */
    public function withPermissions(array $permissionKeys): self
    {
        return $this->state(fn (): array => ['permission_keys' => $permissionKeys]);
    }
}
