<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\ReadModel;

use Logistics\Capacity\Domain\ActiveHorizon;

/**
 * Modelo de lectura del horizonte activo de cupos de un alcance: hasta qué fecha hay cupos activados
 * ("cupos activos hasta <active_until>"). `global` es true cuando aplica a toda la red (municipio null).
 */
final readonly class ActiveHorizonView
{
    public function __construct(
        public ?string $municipalityId,
        public bool $global,
        public string $activeUntil,
    ) {}

    public static function fromDomain(ActiveHorizon $horizon): self
    {
        return new self(
            $horizon->municipalityId(),
            $horizon->municipalityId() === null,
            $horizon->activeUntil()->toString(),
        );
    }

    /**
     * @return array{municipality_id: string|null, global: bool, active_until: string}
     */
    public function toArray(): array
    {
        return [
            'municipality_id' => $this->municipalityId,
            'global' => $this->global,
            'active_until' => $this->activeUntil,
        ];
    }
}
