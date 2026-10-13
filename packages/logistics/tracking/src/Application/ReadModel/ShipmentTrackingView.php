<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

use Logistics\Tracking\Domain\Model\ShipmentTracking;

/**
 * Modelo de lectura INTERNO del seguimiento, para back office y operaciones.
 *
 * A diferencia del PublicTrackingView, sí incluye las claves internas (id de seguimiento, id de envío
 * y token), porque lo consume personal autorizado, no la página pública. Si se cachea, se cachea su
 * toArray() (regla 7).
 */
final readonly class ShipmentTrackingView
{
    /**
     * @param array{lat: float, lng: float, reported_at: string}|null $lastPosition
     */
    public function __construct(
        public string $id,
        public string $shipmentId,
        public string $token,
        public string $status,
        public int $remainingStops,
        public ?string $eta,
        public ?array $lastPosition,
    ) {}

    public static function fromTracking(ShipmentTracking $tracking): self
    {
        return new self(
            $tracking->id()->toString(),
            $tracking->shipmentId(),
            $tracking->token()->toString(),
            $tracking->status()->value,
            $tracking->remainingStops(),
            $tracking->eta()?->toIso8601(),
            $tracking->lastPosition()?->toArray(),
        );
    }

    /**
     * @return array{id: string, shipment_id: string, token: string, status: string, remaining_stops: int, eta: string|null, last_position: array{lat: float, lng: float, reported_at: string}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'token' => $this->token,
            'status' => $this->status,
            'remaining_stops' => $this->remainingStops,
            'eta' => $this->eta,
            'last_position' => $this->lastPosition,
        ];
    }
}
