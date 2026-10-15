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
     * @return list<Trip>
     */
    public function listBy(?string $vehicleId, ?DateTimeImmutable $day): array;
}
