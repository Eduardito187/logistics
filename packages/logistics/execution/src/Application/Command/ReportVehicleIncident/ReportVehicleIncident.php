<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ReportVehicleIncident;

use Logistics\Shared\Application\Command\Command;

/**
 * El chofer reporta un incidente (avería o accidente) con su camión en ruta.
 *
 * `severity` es el value de IncidentSeverity (MINOR | MAJOR); `type`, el de IncidentType
 * (BREAKDOWN | ACCIDENT). `routeId` es opcional: si no viene, se resuelve la ruta abierta vigente del
 * camión. GPS opcional (lo trae la app; el admin puede no tenerlo). Idempotente por idempotencyKey.
 */
final readonly class ReportVehicleIncident implements Command
{
    public function __construct(
        public string $vehicleId,
        public string $severity,
        public string $type,
        public ?string $routeId = null,
        public ?string $note = null,
        public ?float $lat = null,
        public ?float $lng = null,
        public string $deviceId = '',
        public string $userId = '',
        public ?string $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {}
}
