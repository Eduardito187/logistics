<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

use DateTimeImmutable;
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
        public ?string $shipmentId,
        public string $token,
        public string $status,
        public int $remainingStops,
        public ?int $routePosition,
        public ?string $eta,
        public ?array $lastPosition,
        // Referencia externa humana del envío (tabla shipments.external_id) resuelta en el QueryHandler.
        // Null si el envío asociado ya no existe; el UUID crudo se mantiene en shipment_id.
        public ?string $shipmentExternalId = null,
        // ¿La última posición es "en vivo" (fresca) o solo la última conocida? Null si no hay posición.
        public ?bool $positionIsLive = null,
        // Momento (ISO 8601) en que el pedido llegó a su tienda de retiro; null si no llegó o no aplica.
        public ?string $atStoreAt = null,
        // ¿Listo para que el cliente lo recoja? (llegó a la tienda y no está entregado/anulado/devuelto).
        public bool $readyForPickup = false,
    ) {}

    public static function fromTracking(
        ShipmentTracking $tracking,
        ?string $shipmentExternalId = null,
        ?bool $positionIsLive = null,
    ): self {
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
            $tracking->lastPosition() === null ? null : $positionIsLive,
            $tracking->atStoreAt()?->format(DateTimeImmutable::ATOM),
            $tracking->isReadyForPickup(),
        );
    }

    /**
     * @return array{id: string, shipment_id: string|null, shipment_external_id: string|null, token: string, status: string, remaining_stops: int, route_position: int|null, eta: string|null, last_position: array{lat: float, lng: float, reported_at: string}|null, position_is_live: bool|null, at_store_at: string|null, ready_for_pickup: bool}
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
            'position_is_live' => $this->positionIsLive,
            'at_store_at' => $this->atStoreAt,
            'ready_for_pickup' => $this->readyForPickup,
        ];
    }
}
