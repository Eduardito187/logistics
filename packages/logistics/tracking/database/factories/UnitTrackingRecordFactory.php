<?php

declare(strict_types=1);

namespace Logistics\Tracking\Database\Factories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;
use Logistics\Tracking\Domain\ValueObject\UnitTrackingId;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\UnitTrackingRecord;

/**
 * Factory del read model per-bulto (tabla tracking_units).
 *
 * Genera filas coherentes con el dominio: pk, shipment_id y unit_id son UUID y el estado es uno del
 * vocabulario per-bulto. El instante del último escaneo cae en la última media hora para que el
 * detalle de bultos del admin tenga sentido en la demo.
 *
 * @extends Factory<UnitTrackingRecord>
 */
final class UnitTrackingRecordFactory extends Factory
{
    protected $model = UnitTrackingRecord::class;

    public function definition(): array
    {
        $hasLocation = $this->faker->boolean(50);

        return [
            'id' => UnitTrackingId::generate()->toString(),
            'shipment_id' => UnitTrackingId::generate()->toString(),
            'unit_id' => UnitTrackingId::generate()->toString(),
            'status' => $this->faker->randomElement(UnitStatus::cases())->value,
            'last_location_id' => $hasLocation ? UnitTrackingId::generate()->toString() : null,
            'last_seen_at' => $this->faker->dateTimeBetween('-30 minutes', 'now')->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Estado concreto del bulto (p. ej. cargado, entregado).
     */
    public function withStatus(UnitStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    /**
     * Bulto de un envío concreto (para armar el detalle de bultos de un seguimiento).
     */
    public function forShipment(string $shipmentId): self
    {
        return $this->state(fn (): array => ['shipment_id' => $shipmentId]);
    }
}
