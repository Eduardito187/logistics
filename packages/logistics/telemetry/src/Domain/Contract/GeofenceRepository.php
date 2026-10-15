<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use Logistics\Telemetry\Domain\Model\Geofence;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;

/**
 * Puerto de persistencia de geocercas. La implementación vive en Infrastructure.
 */
interface GeofenceRepository
{
    public function nextIdentity(): GeofenceId;

    public function save(Geofence $geofence): void;

    public function findById(GeofenceId $id): ?Geofence;

    /**
     * @return list<Geofence>
     */
    public function all(): array;

    /**
     * Solo las geocercas activas: es lo que evalúa la detección de mal uso durante la ingesta.
     *
     * @return list<Geofence>
     */
    public function activeAll(): array;

    public function delete(GeofenceId $id): void;
}
