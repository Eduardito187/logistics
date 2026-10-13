<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\VehicleId;

/**
 * Puerto de persistencia de vehículos. La implementación vive en Infrastructure.
 */
interface VehicleRepository
{
    public function nextIdentity(): VehicleId;

    public function save(Vehicle $vehicle): void;

    public function findById(VehicleId $id): ?Vehicle;

    /**
     * @return list<Vehicle>
     */
    public function all(): array;
}
