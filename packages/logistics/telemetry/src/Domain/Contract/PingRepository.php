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
     * Inserta un lote. Idempotente por (vehicle_id, recorded_at, fix_key): una lectura ya persistida se
     * ignora en silencio (ON CONFLICT DO NOTHING), así un reintento concurrente no duplica.
     *
     * @param list<VehiclePing> $pings
     */
    public function appendMany(array $pings): void;

    /**
     * De las claves de lectura dadas, cuáles YA están persistidas para el vehículo (acotado por el rango de
     * `recorded_at` del lote, que aprovecha la poda de particiones).
     *
     * @param list<string> $fixKeys
     *
     * @return list<string>
     */
    public function existingFixKeys(string $vehicleId, array $fixKeys, DateTimeImmutable $from, DateTimeImmutable $to): array;

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
