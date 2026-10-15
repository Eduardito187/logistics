<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Contract;

use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
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
     * Busca una zona por su clave estable dentro de un área padre: el nombre acotado al área
     * administrativa a la que pertenece. Es la base de la importación idempotente: dos importaciones
     * del mismo mapa reconocen la misma zona por (nombre, área padre) y la actualizan en vez de
     * duplicarla. El área padre puede ser null (zonas sin jerarquía); se compara con IS NOT DISTINCT.
     */
    public function findByNameAndParent(string $name, ?GeoAreaId $parentAreaId): ?Zone;

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
