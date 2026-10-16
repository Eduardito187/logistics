<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ReportDeliveryIssue;

use Logistics\Shared\Application\Command\Command;

/**
 * El chofer reporta un inconveniente durante una entrega EN CURSO (no la cierra).
 *
 * `type` es el value de DeliveryIssueType (DELAY | ACCESS | CUSTOMER_REQUEST | ADDRESS_UNCLEAR | OTHER).
 * La nota, el GPS y la foto son opcionales (los trae la app). Idempotente por idempotencyKey: el reenvío
 * del mismo reporte de la app offline no duplica el hecho.
 */
final readonly class ReportDeliveryIssue implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $type,
        public ?string $note = null,
        public ?float $lat = null,
        public ?float $lng = null,
        public ?string $photoPath = null,
        public string $deviceId = '',
        public string $userId = '',
        public ?string $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {}
}
