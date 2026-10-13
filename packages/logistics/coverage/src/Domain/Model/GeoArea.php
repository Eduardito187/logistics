<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Model;

use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;

/**
 * Nodo de la jerarquía administrativa: Departamento > Provincia > Municipio > Ciudad.
 *
 * Es una entidad simple (no raíz de agregado): se arma en árbol a través de parentId. Las zonas de
 * cobertura (polígonos) cuelgan de un área mediante Zone::parentAreaId.
 */
final class GeoArea
{
    private function __construct(
        private readonly GeoAreaId $id,
        private readonly string $name,
        private readonly AdminLevel $level,
        private readonly ?GeoAreaId $parentId,
    ) {}

    public static function create(
        GeoAreaId $id,
        string $name,
        AdminLevel $level,
        ?GeoAreaId $parentId,
    ): self {
        return new self($id, $name, $level, $parentId);
    }

    public function id(): GeoAreaId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function level(): AdminLevel
    {
        return $this->level;
    }

    public function parentId(): ?GeoAreaId
    {
        return $this->parentId;
    }
}
