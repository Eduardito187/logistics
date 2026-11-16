<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\ReadModel;

/**
 * Modelo de lectura de la disponibilidad de una franja horaria de despacho: si está bloqueada (por la
 * franja o por el día entero) y por qué motivo. Forma plana y primitiva, lista para serializar.
 */
final readonly class DispatchSlotAvailabilityView
{
    public function __construct(
        public string $date,
        public string $timeSlot,
        public ?string $municipalityId,
        public bool $blocked,
        public ?string $reason,
    ) {}

    /**
     * @return array{date: string, time_slot: string, municipality_id: string|null, blocked: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'time_slot' => $this->timeSlot,
            'municipality_id' => $this->municipalityId,
            'blocked' => $this->blocked,
            'reason' => $this->reason,
        ];
    }
}
