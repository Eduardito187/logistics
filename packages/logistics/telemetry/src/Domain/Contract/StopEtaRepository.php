<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use DateTimeImmutable;

/**
 * Puerto de la proyección de ETA dinámica por parada restante (una fila por envío pendiente). La escribe el
 * comando programado `telemetry:refresh-etas`; la leen las consultas de ETA.
 *
 * @phpstan-type StopEtaRow array{shipment_id: string, vehicle_id: string, sequence: int, stop_lat: float|null, stop_lng: float|null, eta_at: DateTimeImmutable|null, remaining_distance_m: int|null, remaining_duration_s: int|null, method: string, position_recorded_at: DateTimeImmutable, computed_at: DateTimeImmutable}
 */
interface StopEtaRepository
{
    /**
     * Reemplaza las ETAs del vehículo por las recién calculadas: las paradas que ya no están (entregadas,
     * fallidas, reasignadas) desaparecen de la proyección.
     *
     * @param list<StopEtaRow> $rows
     */
    public function replaceForVehicle(string $vehicleId, array $rows): void;

    /**
     * ETAs guardadas del vehículo, en orden de parada.
     *
     * @return list<StopEtaRow>
     */
    public function forVehicle(string $vehicleId): array;
}
