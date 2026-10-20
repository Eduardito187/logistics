<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Model\Trip;
use Logistics\Telemetry\Domain\ValueObject\TripId;

/**
 * Puerto de persistencia de trips. La implementación vive en Infrastructure. `save` es un upsert por id.
 */
interface TripRepository
{
    public function nextIdentity(): TripId;

    public function save(Trip $trip): void;

    public function findById(TripId $id): ?Trip;

    /**
     * Trip activo del vehículo (a lo sumo uno), o null si no hay ninguno abierto.
     */
    public function activeForVehicle(string $vehicleId): ?Trip;

    /**
     * @return list<Trip>
     */
    public function all(): array;

    /**
     * Lista filtrando opcionalmente por vehículo y por día (según `started_at`).
     *
     * `$limit`/`$offset` son opcionales (null = sin recorte): bajan LIMIT/OFFSET a SQL cuando el
     * consumidor pagina, sin cambiar la forma del resultado.
     *
     * @return list<Trip>
     */
    public function listBy(?string $vehicleId, ?DateTimeImmutable $day, ?int $limit = null, ?int $offset = null): array;

    /**
     * Cuenta los trips que cumplen los MISMOS filtros que `listBy`, para el total de la paginación.
     */
    public function countBy(?string $vehicleId, ?DateTimeImmutable $day): int;
}
