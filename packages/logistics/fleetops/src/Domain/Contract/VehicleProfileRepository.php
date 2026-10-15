<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use Logistics\FleetOps\Domain\Model\VehicleProfile;

/**
 * Puerto de persistencia de perfiles técnicos de vehículos. La implementación vive en Infrastructure.
 *
 * No hay nextIdentity: el perfil es 1:1 con el vehículo y se identifica por `vehicleId` (upsert); el id
 * interno del perfil (para eventos) lo genera el handler al crear uno nuevo.
 */
interface VehicleProfileRepository
{
    public function save(VehicleProfile $profile): void;

    public function findByVehicle(string $vehicleId): ?VehicleProfile;

    /**
     * @return list<VehicleProfile>
     */
    public function all(): array;
}
