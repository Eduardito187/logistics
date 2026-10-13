<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use Logistics\Execution\Domain\Model\ScanEvent;

/**
 * Modelo de lectura de un escaneo: forma plana en primitivos, lista para serializar a JSON.
 */
final readonly class ScanEventView
{
    public function __construct(
        public string $id,
        public string $deliveryId,
        public string $type,
        public string $unitId,
        public float $lat,
        public float $lng,
        public string $deviceId,
        public string $userId,
        public string $occurredAt,
        public string $idempotencyKey,
    ) {}

    public static function fromScanEvent(ScanEvent $scan): self
    {
        return new self(
            $scan->id->toString(),
            $scan->deliveryId,
            $scan->type->value,
            $scan->unitId,
            $scan->geo->lat,
            $scan->geo->lng,
            $scan->device->deviceId,
            $scan->device->userId,
            $scan->occurredAt->format(DATE_ATOM),
            $scan->idempotencyKey,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'delivery_id' => $this->deliveryId,
            'type' => $this->type,
            'unit_id' => $this->unitId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'device_id' => $this->deviceId,
            'user_id' => $this->userId,
            'occurred_at' => $this->occurredAt,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
