<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Model\VehiclePing;

/**
 * Puerto de persistencia de pings (append-only, alto volumen). La implementación vive en Infrastructure.
 *
 * No hay update ni delete: un ping es un hecho inmutable. `appendMany` existe para insertar lotes de
 * ingesta de una sola vez.
 */
interface PingRepository
{
    public function append(VehiclePing $ping): void;

    /**
     * @param list<VehiclePing> $pings
     */
    public function appendMany(array $pings): void;

    public function latestForVehicle(string $vehicleId): ?VehiclePing;

    /**
     * Traza de un vehículo entre dos instantes, ordenada por `recorded_at` ascendente.
     *
     * @return list<VehiclePing>
     */
    public function track(string $vehicleId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Ids de todos los vehículos que reportaron al menos un ping en el día indicado. Lo usa el worker de
     * analítica de calles para recorrer la flota completa sin cargar los pings en memoria.
     *
     * @return list<string>
     */
    public function allVehicleIdsWithPingsOn(DateTimeImmutable $day): array;
}
