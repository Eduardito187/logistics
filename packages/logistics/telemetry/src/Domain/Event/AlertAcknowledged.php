<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se reconoció (acknowledge) una alerta: alguien de la operación la tomó.
 */
final readonly class AlertAcknowledged extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $acknowledgedBy,
        public string $acknowledgedAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'telemetry.alert_acknowledged';
    }

    public function payload(): array
    {
        return [
            'acknowledged_by' => $this->acknowledgedBy,
            'acknowledged_at' => $this->acknowledgedAt,
        ];
    }
}
