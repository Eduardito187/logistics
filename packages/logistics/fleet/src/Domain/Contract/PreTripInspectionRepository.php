<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\PreTripInspection;
use Logistics\Fleet\Domain\ValueObject\PreTripInspectionId;

/**
 * Puerto de persistencia de inspecciones pre-salida. La implementación vive en Infrastructure.
 */
interface PreTripInspectionRepository
{
    public function nextIdentity(): PreTripInspectionId;

    public function save(PreTripInspection $inspection): void;

    public function findById(PreTripInspectionId $id): ?PreTripInspection;

    /**
     * @return list<PreTripInspection>
     */
    public function all(): array;

    /**
     * Lista inspecciones con filtros opcionales, en orden determinista: fecha de inspección desc y, a
     * igual fecha, id desc (UUID v7 = más reciente primero). `$limit`/`$offset` bajan LIMIT/OFFSET a SQL
     * al paginar (null = sin recorte).
     *
     * @return list<PreTripInspection>
     */
    public function listBy(
        ?string $vehicleId = null,
        ?bool $onlyPassed = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array;

    /**
     * Cuenta las inspecciones que cumplen los MISMOS filtros que `listBy` (total de la paginación).
     */
    public function countBy(
        ?string $vehicleId = null,
        ?bool $onlyPassed = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): int;

    /**
     * Última inspección (la más reciente) de un vehículo, o null si nunca se inspeccionó.
     */
    public function latestForVehicle(string $vehicleId): ?PreTripInspection;
}
