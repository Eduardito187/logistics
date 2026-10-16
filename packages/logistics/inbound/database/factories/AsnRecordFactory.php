<?php

declare(strict_types=1);

namespace Logistics\Inbound\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\AsnRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de ASNs para seeders de demo y pruebas Feature. Genera el id como UUID v7 real (id tipado del
 * dominio) para coincidir con lo que produce el repositorio en producción.
 *
 * @extends Factory<AsnRecord>
 */
final class AsnRecordFactory extends Factory
{
    protected $model = AsnRecord::class;

    public function definition(): array
    {
        return [
            'id' => AsnId::generate()->toString(),
            'seller_id' => Uuid::generate()->value,
            'warehouse_id' => Uuid::generate()->value,
            'status' => 'announced',
            'appointment_date' => null,
            'appointment_start' => null,
            'appointment_end' => null,
        ];
    }

    /**
     * ASN agendado con una ventana de cita.
     */
    public function scheduled(string $date = '2026-10-20', string $start = '08:00', string $end = '12:00'): self
    {
        return $this->state(fn (): array => [
            'status' => 'scheduled',
            'appointment_date' => $date,
            'appointment_start' => $start,
            'appointment_end' => $end,
        ]);
    }
}
