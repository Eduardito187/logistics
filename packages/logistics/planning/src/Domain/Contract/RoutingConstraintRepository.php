<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;
use Logistics\Planning\Domain\ValueObject\VehicleRouteProfile;

/**
 * Puerta de ESCRITURA de las restricciones de ruteo (R-04): el perfil por camión
 * (planning_vehicle_restrictions) y los requisitos por envío (planning_shipment_requirements). Los
 * adaptadores de disponibilidad y de pendientes los leen en lote; este contrato es su único escritor
 * (Ola 0: "restricciones sin dueño").
 *
 * El dominio la define; la implementación vive en Infrastructure. Una fila por camión / por envío:
 * guardar es un upsert por id.
 */
interface RoutingConstraintRepository
{
    /** Guarda (crea o reemplaza) el perfil de restricciones del camión. */
    public function saveVehicleProfile(string $vehicleId, VehicleRouteProfile $profile): void;

    /** Quita el perfil del camión (vuelve a quedar sin restricciones). Idempotente. */
    public function removeVehicleProfile(string $vehicleId): void;

    /** Guarda (crea o reemplaza) los requisitos de ruteo del envío. */
    public function saveShipmentRequirement(string $shipmentId, ShipmentRouteRequirement $requirement): void;

    /** Quita los requisitos del envío (vuelve a entrar en cualquier camión). Idempotente. */
    public function removeShipmentRequirement(string $shipmentId): void;
}
