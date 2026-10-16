<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DropshipHandling;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Shared\Domain\ValueObject\FulfillmentMode;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de entregas para tests y seeders. Genera filas coherentes con la máquina de estados:
 * las entregas entregadas traen prueba de entrega; las fallidas, su motivo.
 *
 * @extends Factory<DeliveryRecord>
 */
final class DeliveryRecordFactory extends Factory
{
    protected $model = DeliveryRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'shipment_id' => Uuid::generate()->value,
            'vehicle_id' => Uuid::generate()->value,
            'status' => DeliveryStatus::Loaded->value,
            'pod_confirmation' => null,
            'pod_photo_url' => null,
            'pod_captured_at' => null,
            'failure_reason' => null,
            // Por defecto retail (OWNED): el comportamiento histórico. La rama dropship usa el estado dropship().
            'fulfillment_mode' => FulfillmentMode::Owned->value,
            'dropship_handling' => null,
            'dispatch_confirmation_key' => null,
            'delivery_confirmation_key' => null,
        ];
    }

    /**
     * Entrega dropship por confirmación del seller (sin escaneos propios). Por defecto solo-tracking.
     */
    public function dropship(DropshipHandling $handling = DropshipHandling::TrackOnly): self
    {
        return $this->state(fn (): array => [
            'fulfillment_mode' => FulfillmentMode::Dropship->value,
            'dropship_handling' => $handling->value,
        ]);
    }

    /**
     * Entrega completada con prueba de entrega (QR del cliente y foto).
     */
    public function delivered(): self
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Delivered->value,
            'pod_confirmation' => 'QR-'.$this->faker->bothify('########'),
            'pod_photo_url' => $this->faker->imageUrl(),
            'pod_captured_at' => $this->faker->dateTimeThisMonth()->format(DATE_ATOM),
            'failure_reason' => null,
        ]);
    }

    /**
     * Intento de entrega fallido con motivo tipificado.
     */
    public function failed(): self
    {
        $reasons = array_map(fn (FailureReason $reason): string => $reason->value, FailureReason::cases());

        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Failed->value,
            'failure_reason' => $this->faker->randomElement($reasons),
        ]);
    }
}
