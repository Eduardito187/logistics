<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\ReadModel;

use Logistics\Capacity\Domain\DispatchBlackoutDay;

/**
 * Modelo de lectura de un día bloqueado de despacho: forma plana y primitiva, lista para la API o el
 * admin. `global` es true cuando el bloqueo aplica a toda la red (municipio null).
 */
final readonly class BlackoutDayView
{
    public function __construct(
        public string $id,
        public string $date,
        public ?string $municipalityId,
        public bool $global,
        public string $reason,
    ) {}

    public static function fromDomain(DispatchBlackoutDay $day): self
    {
        return new self(
            $day->id()->toString(),
            $day->date()->toString(),
            $day->municipalityId(),
            $day->isGlobal(),
            $day->reason(),
        );
    }

    /**
     * @return array{id: string, date: string, municipality_id: string|null, global: bool, reason: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date,
            'municipality_id' => $this->municipalityId,
            'global' => $this->global,
            'reason' => $this->reason,
        ];
    }
}
