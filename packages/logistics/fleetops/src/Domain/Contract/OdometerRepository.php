<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\OdometerReading;
use Logistics\FleetOps\Domain\ValueObject\OdometerReadingId;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;

/**
 * Puerto de persistencia de lecturas de odómetro. La implementación vive en Infrastructure.
 */
interface OdometerRepository
{
    public function nextIdentity(): OdometerReadingId;

    public function save(OdometerReading $reading): void;

    /**
     * Última lectura (la más reciente por fecha de lectura) de un vehículo, si existe.
     */
    public function latestForVehicle(string $vehicleId): ?OdometerReading;

    /**
     * ¿La ruta ya tiene una lectura de la fuente dada (p. ej. su llegada)? Una ruta se cierra una sola vez.
     */
    public function existsForRouteAndSource(string $routeId, OdometerSource $source): bool;

    /**
     * Lista lecturas con filtros opcionales: por vehículo, por rango de la fecha de lectura y por ruta.
     *
     * `$limit`/`$offset` son opcionales (null = sin recorte): bajan LIMIT/OFFSET a SQL al paginar.
     *
     * @return list<OdometerReading>
     */
    public function listBy(
        ?string $vehicleId = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $routeId = null,
    ): array;

    /**
     * Cuenta las lecturas que cumplen los MISMOS filtros que `listBy`, para el total de la paginación.
     */
    public function countBy(
        ?string $vehicleId = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        ?string $routeId = null,
    ): int;
}
