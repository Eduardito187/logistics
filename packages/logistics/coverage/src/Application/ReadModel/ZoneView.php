<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\ReadModel;

use Logistics\Coverage\Domain\Model\Zone;

/**
 * Modelo de lectura de una zona: forma plana y serializable para respuestas de API y consola.
 */
final readonly class ZoneView
{
    /**
     * @param array{type: string, coordinates: array<int, array<int, array{0: float, 1: float}>>} $polygon
     * @param array<string, mixed>                                                                $rules
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $parentAreaId,
        public bool $active,
        public array $polygon,
        public array $rules,
    ) {}

    public static function fromZone(Zone $zone): self
    {
        return new self(
            $zone->id()->toString(),
            $zone->name(),
            $zone->parentAreaId()?->toString(),
            $zone->isActive(),
            $zone->polygon()->toGeoJson(),
            $zone->rules()->toArray(),
        );
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     parentAreaId: string|null,
     *     active: bool,
     *     polygon: array{type: string, coordinates: array<int, array<int, array{0: float, 1: float}>>},
     *     rules: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parentAreaId' => $this->parentAreaId,
            'active' => $this->active,
            'polygon' => $this->polygon,
            'rules' => $this->rules,
        ];
    }
}
