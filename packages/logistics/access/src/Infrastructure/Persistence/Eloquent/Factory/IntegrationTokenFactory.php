<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Factory;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\IntegrationTokenRecord;

/**
 * Fábrica de tokens de integración para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce
 * el repositorio en producción. No pasa por el dominio a propósito: una fábrica es un detalle de
 * infraestructura que arma filas directamente. Solo persiste el hash del secreto, nunca el valor en claro.
 *
 * @extends Factory<IntegrationTokenRecord>
 */
final class IntegrationTokenFactory extends Factory
{
    protected $model = IntegrationTokenRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => IntegrationTokenId::generate()->toString(),
            'name' => $this->faker->unique()->company().' Integration',
            'token_prefix' => 'itk_'.Str::lower(Str::random(12)),
            'token_hash' => hash('sha256', Str::random(48)),
            'permission_keys' => [],
            'status' => 'active',
            'expires_at' => null,
            'last_used_at' => null,
            'created_by' => null,
        ];
    }

    /**
     * Token revocado.
     */
    public function revoked(): self
    {
        return $this->state(fn (): array => ['status' => 'revoked']);
    }

    /**
     * Token con los permisos indicados (por clave).
     *
     * @param list<string> $permissionKeys
     */
    public function withPermissions(array $permissionKeys): self
    {
        return $this->state(fn (): array => ['permission_keys' => array_values($permissionKeys)]);
    }

    /**
     * Token con fecha de expiración.
     */
    public function expiringAt(DateTimeImmutable $expiresAt): self
    {
        return $this->state(fn (): array => ['expires_at' => $expiresAt]);
    }
}
