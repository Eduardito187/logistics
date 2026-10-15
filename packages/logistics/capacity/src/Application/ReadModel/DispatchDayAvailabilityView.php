<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\ReadModel;

/**
 * Modelo de lectura de la disponibilidad de despacho de un (municipio, fecha): si está bloqueado y por
 * qué motivo (cuando hay un bloqueo concreto que lo explica), y si cae dentro del horizonte activo.
 */
final readonly class DispatchDayAvailabilityView
{
    public function __construct(
        public string $date,
        public ?string $municipalityId,
        public bool $blocked,
        public ?string $reason,
        public bool $withinHorizon,
        public ?string $activeUntil,
    ) {}

    /**
     * @return array{date: string, municipality_id: string|null, blocked: bool, reason: string|null, within_horizon: bool, active_until: string|null}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'municipality_id' => $this->municipalityId,
            'blocked' => $this->blocked,
            'reason' => $this->reason,
            'within_horizon' => $this->withinHorizon,
            'active_until' => $this->activeUntil,
        ];
    }
}
