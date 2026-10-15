<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\UserRecord;

/**
 * Fábrica de usuarios para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce
 * el repositorio en producción. No pasa por el dominio a propósito: una fábrica es un detalle de
 * infraestructura que arma filas directamente. La contraseña de demo es siempre 'password123'.
 *
 * @extends Factory<UserRecord>
 */
final class UserFactory extends Factory
{
    protected $model = UserRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => UserId::generate()->toString(),
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password_hash' => bcrypt('password123'),
            'status' => 'active',
            'role_keys' => [],
            'last_login_at' => null,
        ];
    }

    /**
     * Usuario suspendido.
     */
    public function suspended(): self
    {
        return $this->state(fn (): array => ['status' => 'suspended']);
    }

    /**
     * Usuario invitado (dado de alta pero sin activar su acceso).
     */
    public function invited(): self
    {
        return $this->state(fn (): array => ['status' => 'invited']);
    }

    /**
     * Usuario con los roles indicados (por clave).
     *
     * @param list<string> $roleKeys
     */
    public function withRoles(array $roleKeys): self
    {
        return $this->state(fn (): array => ['role_keys' => array_values($roleKeys)]);
    }
}
