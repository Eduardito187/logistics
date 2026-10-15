<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Access\Domain\ValueObject\AuditEntryId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\AuditEntryRecord;

/**
 * Fábrica de entradas de auditoría para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce el
 * repositorio en producción. No pasa por el dominio a propósito: una fábrica es un detalle de
 * infraestructura que arma filas directamente.
 *
 * @extends Factory<AuditEntryRecord>
 */
final class AuditEntryFactory extends Factory
{
    protected $model = AuditEntryRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => AuditEntryId::generate()->toString(),
            'actor_id' => AuditEntryId::generate()->toString(),
            'actor_label' => $this->faker->name(),
            'action' => 'user.suspended',
            'subject_type' => 'access.user',
            'subject_id' => AuditEntryId::generate()->toString(),
            'context' => [],
            'ip_address' => $this->faker->ipv4(),
            'occurred_at' => $this->faker->dateTimeThisYear(),
        ];
    }

    /**
     * Entrada de una acción del propio sistema (sin autor humano).
     */
    public function bySystem(): self
    {
        return $this->state(fn (): array => ['actor_id' => null, 'actor_label' => 'System']);
    }
}
