<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use Logistics\Telemetry\Domain\Model\TelemetryAlert;
use Logistics\Telemetry\Domain\ValueObject\AlertId;

/**
 * Puerto de persistencia de alertas de telemetría. La implementación vive en Infrastructure.
 */
interface AlertRepository
{
    public function nextIdentity(): AlertId;

    public function save(TelemetryAlert $alert): void;

    public function findById(AlertId $id): ?TelemetryAlert;

    /**
     * Lista alertas con filtros opcionales por vehículo, por tipo y por estado de reconocimiento.
     *
     * `$limit`/`$offset` son opcionales (null = sin recorte): bajan LIMIT/OFFSET a SQL al paginar.
     *
     * @return list<TelemetryAlert>
     */
    public function listBy(
        ?string $vehicleId = null,
        ?string $type = null,
        ?bool $acknowledged = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array;

    /**
     * Cuenta las alertas que cumplen los MISMOS filtros que `listBy`, para el total de la paginación.
     */
    public function countBy(?string $vehicleId = null, ?string $type = null, ?bool $acknowledged = null): int;
}
