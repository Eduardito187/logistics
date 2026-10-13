<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Contract;

use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;

/**
 * Puerta de persistencia del agregado VehicleRoute (la ruta del chofer). El dominio la define; la
 * implementación Eloquent vive en Infrastructure. Reconstruye la ruta completa con sus paradas en orden.
 */
interface VehicleRouteRepository
{
    /**
     * Reserva una identidad nueva. El id lo genera el dominio (UUID v7), no la base.
     */
    public function nextIdentity(): RouteId;

    public function save(VehicleRoute $route): void;

    public function findById(RouteId $id): ?VehicleRoute;

    /**
     * Ruta de un camión para un día concreto (una por camión y día). Null si no se armó.
     */
    public function findByVehicleAndDate(string $vehicleId, RouteDate $date): ?VehicleRoute;

    /**
     * Ruta abierta más reciente de un camión (Planned o InProgress): su hoja de ruta vigente. Null si no
     * tiene ninguna sin terminar.
     */
    public function findOpenByVehicleId(string $vehicleId): ?VehicleRoute;

    /**
     * Ruta que contiene una parada para el envío dado, si existe. Sirve para avanzar la ruta cuando se
     * registra el desenlace de la entrega de ese envío.
     */
    public function findByShipmentId(string $shipmentId): ?VehicleRoute;
}
