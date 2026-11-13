<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\OpenCompensationCase;

use Logistics\Shared\Application\Command\Command;

/**
 * Abre un caso de compensación (manual desde el admin/call center o AUTOMÁTICO desde un reactor del outbox).
 *
 * La base se resuelve así: si llega `manualBaseCents`, es base declarada (línea sin precio, regla 6, siempre
 * requiere aprobación); si no, se lee del envío por el puerto {@see
 * \Logistics\Compensation\Application\Contract\AffectedValueReader} según el alcance.
 *
 * Idempotente por `idempotencyKey` (default `trigger:shipmentId`): una reentrega del outbox no abre el caso
 * dos veces.
 */
final readonly class OpenCompensationCase implements Command
{
    /**
     * @param list<string>                 $affectedSkus SKUs afectados (alcance LINE)
     * @param list<array<string, mixed>>   $manualLines  líneas declaradas para base manual (nombre, sku, qty, precio)
     */
    public function __construct(
        public string $trigger,
        public string $scope,
        public ?string $shipmentId = null,
        public ?string $orderReference = null,
        public ?string $sourceEventId = null,
        public ?string $idempotencyKey = null,
        public array $affectedSkus = [],
        public ?int $manualBaseCents = null,
        public array $manualLines = [],
        public string $proposedBy = 'system',
        public ?string $ownership = null,
        public ?string $driverId = null,
        public ?string $vehicleId = null,
        public ?string $originNodeId = null,
    ) {}
}
