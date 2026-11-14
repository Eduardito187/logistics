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
     * Reconstruye la vista desde su forma primitiva ({@see toArray()}), que es lo que se guarda en el
     * caché de lectura. Devuelve null si faltan los campos obligatorios o tienen otro tipo (entrada
     * corrupta o de otra versión): el llamador descarta la entrada y relee de la base.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $status = $data['status'] ?? null;
        $remainingStops = $data['remaining_stops'] ?? null;
        $stopsMessage = $data['stops_message'] ?? null;

        if (!is_string($status) || !is_int($remainingStops) || !is_string($stopsMessage)) {
            return null;
        }

        $routePosition = $data['route_position'] ?? null;
        $eta = $data['eta'] ?? null;
        $positionIsLive = $data['position_is_live'] ?? null;

        return new self(
            $status,
            $remainingStops,
            is_int($routePosition) ? $routePosition : null,
            is_string($eta) ? $eta : null,
            self::positionFromArray($data['last_position'] ?? null),
            $stopsMessage,
            is_bool($positionIsLive) ? $positionIsLive : null,
        );
    }

    /**
     * Última posición desde su forma primitiva, o null si no vino o está incompleta.
     *
     * @return array{lat: float, lng: float, reported_at: string}|null
     */
    private static function positionFromArray(mixed $position): ?array
    {
        if (!is_array($position)) {
            return null;
        }

        $lat = $position['lat'] ?? null;
        $lng = $position['lng'] ?? null;
        $reportedAt = $position['reported_at'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lng) || !is_string($reportedAt)) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng, 'reported_at' => $reportedAt];
    }

    /**
     * Arma el mensaje al cliente según el estado y las paradas restantes. En español (copy de cliente).
     */
    private static function buildStopsMessage(VisibleStatus $status, int $remainingStops): string
    {
        return match ($status) {
            VisibleStatus::Pending => 'Recibimos tu pedido. Estamos esperando la confirmación del pago.',
            VisibleStatus::Processing => 'Pago confirmado. Estamos preparando tu pedido.',
            VisibleStatus::InProgress => 'Tu pedido está en preparación y pronto saldrá en ruta.',
            VisibleStatus::Loaded => 'Tu pedido ya está cargado en el camión.',
            VisibleStatus::OnTheWay => self::onTheWayMessage($remainingStops),
            VisibleStatus::Delivered => 'Tu pedido fue entregado.',
            VisibleStatus::Failed => 'No pudimos entregar tu pedido. Nos pondremos en contacto contigo.',
            VisibleStatus::Rescheduled => 'Tu entrega fue reprogramada. Te avisaremos la nueva fecha.',
            VisibleStatus::Cancelled => 'Tu pedido fue anulado.',
            VisibleStatus::Returned => 'Registramos la devolución de tu pedido.',
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
