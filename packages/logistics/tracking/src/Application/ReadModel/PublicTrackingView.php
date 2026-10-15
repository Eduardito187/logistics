<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Modelo de lectura de la página PÚBLICA de rastreo (acceso por token).
 *
 * NO expone datos personales ni claves internas: nada de id de seguimiento, id de envío, token,
 * nombre o dirección del cliente. Solo lo que puede ver cualquiera con el enlace: estado, paradas
 * restantes, posición del envío en la ruta, ETA, última posición del camión y un mensaje legible
 * ("Tu pedido está a N paradas"). Si se cachea, se cachea su toArray() (regla 7).
 *
 * El mensaje va en ESPAÑOL a propósito: es copy orientado al cliente boliviano, no texto de admin.
 */
final readonly class PublicTrackingView
{
    /**
     * @param array{lat: float, lng: float, reported_at: string}|null $lastPosition
     */
    public function __construct(
        public string $status,
        public int $remainingStops,
        public ?int $routePosition,
        public ?string $eta,
        public ?array $lastPosition,
        public string $stopsMessage,
        // ¿La última posición es "en vivo" (fresca dentro de la ventana) o solo la última conocida?
        // Null cuando no hay posición. Lo decide el QueryHandler con el Clock de la app.
        public ?bool $positionIsLive = null,
    ) {}

    public static function fromTracking(ShipmentTracking $tracking, ?bool $positionIsLive = null): self
    {
        return new self(
            $tracking->status()->value,
            $tracking->remainingStops(),
            $tracking->routePosition()?->value,
            $tracking->eta()?->toIso8601(),
            $tracking->lastPosition()?->toArray(),
            self::buildStopsMessage($tracking->status(), $tracking->remainingStops()),
            $tracking->lastPosition() === null ? null : $positionIsLive,
        );
    }

    /**
     * Arma el mensaje al cliente según el estado y las paradas restantes. En español (copy de cliente).
     */
    private static function buildStopsMessage(VisibleStatus $status, int $remainingStops): string
    {
        return match ($status) {
            VisibleStatus::Delivered => 'Tu pedido fue entregado.',
            VisibleStatus::Failed => 'No pudimos entregar tu pedido. Nos pondremos en contacto contigo.',
            VisibleStatus::Registered => 'Recibimos tu pedido y lo estamos preparando.',
            VisibleStatus::Prepared => 'Tu pedido está listo y pronto saldrá en ruta.',
            VisibleStatus::OnTheWay, VisibleStatus::Arriving => self::onTheWayMessage($remainingStops),
        };
    }

    /**
     * Mensaje de "en camino" en función de cuántas paradas faltan antes de la del cliente.
     */
    private static function onTheWayMessage(int $remainingStops): string
    {
        return match (true) {
            $remainingStops <= 0 => 'Tu pedido es la próxima parada.',
            $remainingStops === 1 => 'Tu pedido está a 1 parada.',
            default => "Tu pedido está a {$remainingStops} paradas.",
        };
    }

    /**
     * @return array{status: string, remaining_stops: int, route_position: int|null, eta: string|null, last_position: array{lat: float, lng: float, reported_at: string}|null, position_is_live: bool|null, stops_message: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'remaining_stops' => $this->remainingStops,
            'route_position' => $this->routePosition,
            'eta' => $this->eta,
            'last_position' => $this->lastPosition,
            'position_is_live' => $this->positionIsLive,
            'stops_message' => $this->stopsMessage,
        ];
    }
}
