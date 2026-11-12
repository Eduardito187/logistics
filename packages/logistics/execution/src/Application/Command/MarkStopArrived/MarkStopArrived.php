<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\MarkStopArrived;

use Logistics\Shared\Application\Command\Command;

/**
 * El camión LLEGÓ a la parada del envío (POST /app/deliveries/{shipmentId}/arrived). `source` es el value
 * de ArrivalSource (geofence|manual). La primera llegada gana: reintentos y llegadas posteriores son no-op.
 */
final readonly class MarkStopArrived implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $occurredAt,
        public string $source,
        public ?float $lat = null,
        public ?float $lng = null,
        public ?float $accuracyM = null,
    ) {}
}
