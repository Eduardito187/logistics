<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Persistence\Eloquent\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de mensajes del outbox para seeders y tests. Genera eventos realistas de distintos
 * contextos (network, shipments, execution, notifications, sap) con su payload propio.
 *
 * @extends Factory<OutboxMessageRecord>
 */
final class OutboxMessageFactory extends Factory
{
    /** @var class-string<OutboxMessageRecord> */
    protected $model = OutboxMessageRecord::class;

    /**
     * Catálogo de eventos de ejemplo: aggregateType + eventName + payload plausible.
     *
     * @var list<array{aggregate_type: string, event_name: string, payload: array<string, mixed>}>
     */
    private const EVENT_CATALOG = [
        [
            'aggregate_type' => 'network.node',
            'event_name' => 'network.node_registered',
            'payload' => ['code' => 'CD-LPZ-01', 'type' => 'distribution_center', 'city' => 'La Paz'],
        ],
        [
            'aggregate_type' => 'shipments.shipment',
            'event_name' => 'shipments.shipment_accepted',
            'payload' => ['order' => 'DM-100245', 'packages' => 3, 'corridor' => 'cd_to_customer'],
        ],
        [
            'aggregate_type' => 'execution.delivery',
            'event_name' => 'execution.proof_of_delivery_recorded',
            'payload' => ['order' => 'DM-100245', 'receiver' => 'Juan Pérez', 'signed' => true],
        ],
        [
            'aggregate_type' => 'notifications.notification',
            'event_name' => 'notifications.fcm.delivery_on_the_way',
            'payload' => ['order' => 'DM-100245', 'topic' => 'order_DM_100245', 'eta_minutes' => 25],
        ],
        [
            'aggregate_type' => 'sap.transfer',
            'event_name' => 'sap.stock_transfer_posted',
            'payload' => ['material' => '000000000040012345', 'from' => '1000', 'to' => '2000', 'qty' => 5],
        ],
    ];

    public function definition(): array
    {
        /** @var array{aggregate_type: string, event_name: string, payload: array<string, mixed>} $event */
        $event = $this->faker->randomElement(self::EVENT_CATALOG);
        $createdAt = $this->faker->dateTimeBetween('-7 days', 'now');

        return [
            'id' => Uuid::generate()->value,
            'event_id' => Uuid::generate()->value,
            'aggregate_type' => $event['aggregate_type'],
            'aggregate_id' => Uuid::generate()->value,
            'event_name' => $event['event_name'],
            'payload' => $event['payload'],
            'status' => OutboxStatus::Pending->value,
            'attempts' => 0,
            'available_at' => $createdAt,
            'created_at' => $createdAt,
            'dispatched_at' => null,
            'last_error' => null,
        ];
    }

    /**
     * Mensaje ya despachado con éxito (estado terminal feliz).
     */
    public function dispatched(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OutboxStatus::Dispatched->value,
            'attempts' => 1,
            'dispatched_at' => $this->faker->dateTimeBetween('-7 days', 'now'),
            'last_error' => null,
        ]);
    }

    /**
     * Mensaje que falló y está esperando su próximo intento (backoff).
     */
    public function failed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OutboxStatus::Failed->value,
            'attempts' => $this->faker->numberBetween(1, 4),
            'available_at' => $this->faker->dateTimeBetween('now', '+2 hours'),
            'last_error' => 'SAP endpoint timed out after 30s',
        ]);
    }

    /**
     * Mensaje descartado tras agotar los reintentos (dead-letter): requiere intervención manual.
     */
    public function deadLettered(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OutboxStatus::DeadLettered->value,
            'attempts' => 6,
            'last_error' => 'SAP rejected the transfer: material 000000000040012345 is blocked',
        ]);
    }
}
