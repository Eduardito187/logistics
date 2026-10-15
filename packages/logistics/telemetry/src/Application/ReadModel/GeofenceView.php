<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\ReadModel;

use Logistics\Telemetry\Domain\Model\Geofence;

/**
 * Proyección de lectura de una geocerca para listados, admin y API. Primitivos: no expone objetos de
 * dominio.
 */
final readonly class GeofenceView
{
    /**
     * @param list<array{lat: float, lng: float}> $polygon
     */
    private function __construct(
        public string $id,
        public string $name,
        public string $type,
        public array $polygon,
        public bool $active,
        public ?string $nodeRef,
    ) {}

    public static function fromDomain(Geofence $geofence): self
    {
        return new self(
            $geofence->id()->toString(),
            $geofence->name(),
            $geofence->type()->value,
            $geofence->polygon()->toArray(),
            $geofence->isActive(),
            $geofence->nodeRef(),
        );
    }

    /**
     * @return array{id: string, name: string, type: string, polygon: list<array{lat: float, lng: float}>, active: bool, node_ref: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'polygon' => $this->polygon,
            'active' => $this->active,
            'node_ref' => $this->nodeRef,
        ];
    }
}
