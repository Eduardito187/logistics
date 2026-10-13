<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

use Logistics\Tracking\Domain\Model\ShipmentTracking;

/**
 * Modelo de lectura de la página PÚBLICA de rastreo (acceso por token).
 *
 * NO expone datos personales ni claves internas: nada de id de seguimiento, id de envío, token,
 * nombre o dirección del cliente. Solo lo que puede ver cualquiera con el enlace: estado, paradas
 * restantes, ETA y la última posición del camión. Si se cachea, se cachea su toArray() (regla 7).
 */
final readonly class PublicTrackingView
{
    /**
     * @param array{lat: float, lng: float, reported_at: string}|null $lastPosition
     */
    public function __construct(
        public string $status,
        public int $remainingStops,
        public ?string $eta,
        public ?array $lastPosition,
    ) {}

    public static function fromTracking(ShipmentTracking $tracking): self
    {
        return new self(
            $tracking->status()->value,
            $tracking->remainingStops(),
            $tracking->eta()?->toIso8601(),
            $tracking->lastPosition()?->toArray(),
        );
    }

    /**
     * @return array{status: string, remaining_stops: int, eta: string|null, last_position: array{lat: float, lng: float, reported_at: string}|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'remaining_stops' => $this->remainingStops,
            'eta' => $this->eta,
            'last_position' => $this->lastPosition,
        ];
    }
}
