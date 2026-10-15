<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Contract;

use Logistics\Coverage\Domain\Model\GeoArea;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;

/**
 * Puerto de persistencia de la jerarquía administrativa.
 */
interface GeoAreaRepository
{
    public function nextIdentity(): GeoAreaId;

    public function save(GeoArea $area): void;

    public function findById(GeoAreaId $id): ?GeoArea;

    /**
     * Busca un área por su clave estable dentro de la jerarquía: el nombre acotado a un nivel
     * administrativo (p. ej. "Santa Cruz" a nivel Department). Permite que la importación por
     * departamento reuse el área existente en vez de crear un departamento duplicado en cada import.
     */
    public function findByNameAndLevel(string $name, AdminLevel $level): ?GeoArea;

    /**
     * @return array<int, GeoArea>
     */
    public function all(): array;
}
