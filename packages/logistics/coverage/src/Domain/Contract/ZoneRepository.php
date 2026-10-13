<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Contract;

use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Coverage\Domain\ValueObject\ZoneId;

/**
 * Puerto de persistencia de zonas. La implementación vive en Infrastructure (PostGIS).
 */
interface ZoneRepository
{
    public function nextIdentity(): ZoneId;

    public function save(Zone $zone): void;

    public function findById(ZoneId $id): ?Zone;

    /**
     * @return array<int, Zone>
     */
    public function all(): array;

    /**
     * Resuelve en qué zona cae un punto. Se resuelve en la base con ST_Contains sobre el índice
     * espacial; devuelve null si ninguna zona contiene el punto.
     */
    public function findZoneContaining(GeoPoint $point): ?Zone;
}
