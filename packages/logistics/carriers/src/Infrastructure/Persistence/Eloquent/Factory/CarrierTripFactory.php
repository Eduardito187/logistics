<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierTripRecord;

/**
 * Fábrica de viajes de transportista (solo cabecera) para pruebas Feature y demo. La carga se agrega
 * aparte (tabla carrier_trip_shipments) o despachando AssignShipmentsToCarrierTrip.
 *
 * @extends Factory<CarrierTripRecord>
 */
final class CarrierTripFactory extends Factory
{
    protected $model = CarrierTripRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => CarrierTripId::generate()->toString(),
            'carrier_id' => CarrierId::generate()->toString(),
            'corridor_from' => 'CD-'.$this->faker->bothify('???-##'),
            'corridor_to' => 'CD-'.$this->faker->bothify('???-##'),
            'reference' => 'TRIP-'.$this->faker->unique()->numerify('######'),
            'status' => 'scheduled',
            'scheduled_at' => new DateTimeImmutable('+1 day'),
            'started_at' => null,
            'closed_at' => null,
            'cancelled_at' => null,
            'started_by' => null,
            'closed_by' => null,
        ];
    }

    /**
     * Viaje de un transportista concreto.
     */
    public function forCarrier(string $carrierId): self
    {
        return $this->state(fn (): array => ['carrier_id' => $carrierId]);
    }

    /**
     * Viaje sobre un corredor concreto (ids o códigos de nodo).
     */
    public function onCorridor(string $from, string $to): self
    {
        return $this->state(fn (): array => ['corridor_from' => $from, 'corridor_to' => $to]);
    }

    /**
     * Viaje en un estado concreto (scheduled|in_progress|closed|cancelled).
     */
    public function inStatus(string $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
