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
        public ?int $routePosition,
        public ?string $eta,
        public ?array $lastPosition,
        // Referencia externa humana del envío (tabla shipments.external_id) resuelta en el QueryHandler.
        // Null si el envío asociado ya no existe; el UUID crudo se mantiene en shipment_id.
        public ?string $shipmentExternalId = null,
    ) {}

    public static function fromTracking(ShipmentTracking $tracking, ?string $shipmentExternalId = null): self
    {
        return new self(
            $tracking->id()->toString(),
            $tracking->shipmentId(),
            $tracking->token()->toString(),
            $tracking->status()->value,
            $tracking->remainingStops(),
            $tracking->routePosition()?->value,
            $tracking->eta()?->toIso8601(),
            $tracking->lastPosition()?->toArray(),
            $shipmentExternalId,
        );
    }

    /**
     * @return array{id: string, shipment_id: string, shipment_external_id: string|null, token: string, status: string, remaining_stops: int, route_position: int|null, eta: string|null, last_position: array{lat: float, lng: float, reported_at: string}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'shipment_external_id' => $this->shipmentExternalId,
            'token' => $this->token,
            'status' => $this->status,
            'remaining_stops' => $this->remainingStops,
            'route_position' => $this->routePosition,
            'eta' => $this->eta,
            'last_position' => $this->lastPosition,
        ];
    }
}
