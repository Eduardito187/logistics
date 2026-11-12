<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Model\VehiclePing;

/**
 * Puerto de la posición EN VIVO de cada vehículo: una sola fila por vehículo, pisada con el último ping.
 *
 * Es la proyección optimizada para pintar la flota en el mapa sin escanear la tabla de pings. Devuelve
 * filas primitivas (array shape) porque el contrato de dominio no conoce los ReadModels de Application.
 *
 * `driver_id` y `updated_at` (hora del servidor en que la fila cambió) son opcionales en la forma para no
 * romper a quien arme filas a mano; el repositorio Eloquent siempre los incluye.
 *
 * @phpstan-type LivePositionRow array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: DateTimeImmutable, driver_id?: string|null, updated_at?: DateTimeImmutable|null}
 * @phpstan-type WatchState array{speeding_since: DateTimeImmutable|null, off_route_since: DateTimeImmutable|null}
 */
interface VehiclePositionRepository
{
    /**
     * Pisa la posición en vivo del vehículo con los datos de este ping. `moving` lo decide el llamador
     * (según el umbral de movimiento vigente), para no acoplar el umbral al repositorio.
     */
    public function upsertFromPing(VehiclePing $ping, bool $moving): void;

    /**
     * Posición en vivo de toda la flota.
     *
     * @return list<LivePositionRow>
     */
    public function all(): array;

    /**
     * Posición en vivo de un vehículo, o null si nunca reportó.
     *
     * @return LivePositionRow|null
     */
    public function forVehicle(string $vehicleId): ?array;

    /**
     * Posiciones que CAMBIARON (hora del servidor) desde `$since` inclusive (al-menos-una-vez): polling incremental
     * de la flota en vivo (`?since=`).
     *
     * @return list<LivePositionRow>
     */
    public function changedSince(DateTimeImmutable $since): array;

    /**
     * Estado de las condiciones sostenidas del vehículo (desde cuándo excede la velocidad / está fuera de
     * ruta). Sin fila: ambos null.
     *
     * @return WatchState
     */
    public function watchState(string $vehicleId): array;

    /**
     * Persiste el estado de las condiciones sostenidas. No toca la posición ni `updated_at` de polling
     * (no es un cambio de posición).
     */
    public function saveWatchState(string $vehicleId, ?DateTimeImmutable $speedingSince, ?DateTimeImmutable $offRouteSince): void;
}
