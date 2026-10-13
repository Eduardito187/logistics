<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Model;

use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Tracking\Domain\Event\ShipmentPlacedOnRoute;
use Logistics\Tracking\Domain\Event\TrackingOpened;
use Logistics\Tracking\Domain\Event\TrackingStatusChanged;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\Eta;
use Logistics\Tracking\Domain\ValueObject\RoutePosition;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\TruckPosition;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Agregado raíz del contexto de seguimiento: el estado visible de un envío para el cliente.
 *
 * Es principalmente un read model (una proyección de los eventos de execution), pero se modela como
 * agregado propio para poder evolucionar sus reglas: no deja retroceder desde un estado terminal, no
 * graba eventos redundantes y nunca guarda datos personales. Referencia al envío por id string
 * (shipmentId), nunca importando el agregado de otro contexto.
 */
final class ShipmentTracking extends AggregateRoot
{
    private function __construct(
        private readonly TrackingId $id,
        private readonly string $shipmentId,
        private readonly TrackingToken $token,
        private VisibleStatus $status,
        private int $remainingStops,
        private ?Eta $eta,
        private ?TruckPosition $lastPosition,
        private ?string $routeId,
        private ?RoutePosition $routePosition,
    ) {}

    /**
     * Abre el seguimiento de un envío: estado inicial Registered, token público recién generado y
     * graba el hecho TrackingOpened.
     */
    public static function open(
        TrackingId $id,
        string $shipmentId,
        TrackingToken $token,
        int $remainingStops,
    ): self {
        $shipmentId = trim($shipmentId);
        if ($shipmentId === '') {
            throw TrackingException::because('el id del envío no puede estar vacío');
        }
        if ($remainingStops < 0) {
            throw TrackingException::because("las paradas restantes no pueden ser negativas: {$remainingStops}");
        }

        $tracking = new self(
            $id,
            $shipmentId,
            $token,
            VisibleStatus::Registered,
            $remainingStops,
            null,
            null,
            null,
            null,
        );

        $tracking->recordEvent(new TrackingOpened(
            $id->toString(),
            $shipmentId,
            $token->toString(),
            VisibleStatus::Registered->value,
            $remainingStops,
        ));

        return $tracking;
    }

    /**
     * Reconstituye el agregado desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     */
    public static function reconstitute(
        TrackingId $id,
        string $shipmentId,
        TrackingToken $token,
        VisibleStatus $status,
        int $remainingStops,
        ?Eta $eta,
        ?TruckPosition $lastPosition,
        ?string $routeId = null,
        ?RoutePosition $routePosition = null,
    ): self {
        return new self(
            $id,
            $shipmentId,
            $token,
            $status,
            $remainingStops,
            $eta,
            $lastPosition,
            $routeId,
            $routePosition,
        );
    }

    /**
     * Aplica un nuevo estado visible. Idempotente: si no cambia, no graba evento. No permite retroceder
     * desde un estado terminal (entregado/fallido).
     */
    public function applyStatus(VisibleStatus $status): void
    {
        if ($this->status === $status) {
            return;
        }
        if ($this->status->isTerminal()) {
            throw TrackingException::because(
                "el seguimiento ya está en estado terminal {$this->status->value}; no puede pasar a {$status->value}",
            );
        }

        $previous = $this->status;
        $this->status = $status;

        $this->recordEvent(new TrackingStatusChanged(
            $this->id->toString(),
            $this->shipmentId,
            $previous->value,
            $status->value,
        ));
    }

    /**
     * Actualiza la posición en vivo del camión. No graba evento de dominio: es un dato de alta
     * frecuencia (telemetría), no un hecho de negocio.
     */
    public function updatePosition(TruckPosition $position): void
    {
        $this->lastPosition = $position;
    }

    /**
     * Fija o reemplaza la hora estimada de llegada. Tampoco es un hecho de negocio por sí mismo.
     */
    public function setEta(Eta $eta): void
    {
        $this->eta = $eta;
    }

    /**
     * Ubica el envío en la ruta publicada del camión: fija la ruta y la posición en la secuencia, y
     * deriva cuántas paradas faltan ANTES de la del cliente (stopsBefore). Graba ShipmentPlacedOnRoute.
     *
     * Es la proyección de la ruta publicada por planning (recibida como dato por el puente, sin acoplar
     * el agregado de otro contexto). Idempotente por su efecto: reproyectar la misma ruta deja el mismo
     * estado. No toca un seguimiento ya terminal (entregado/fallido): la ruta ya no lo mueve.
     */
    public function placeOnRoute(string $routeId, RoutePosition $position): void
    {
        if ($this->status->isTerminal()) {
            return;
        }

        $routeId = trim($routeId);
        if ($routeId === '') {
            throw TrackingException::because('el id de la ruta no puede estar vacío');
        }

        $this->routeId = $routeId;
        $this->routePosition = $position;
        $this->remainingStops = $position->stopsBefore();

        $this->recordEvent(new ShipmentPlacedOnRoute(
            $this->id->toString(),
            $this->shipmentId,
            $routeId,
            $position->value,
            $this->remainingStops,
        ));
    }

    /**
     * Descuenta una parada restante: una parada previa de la ruta se completó, así que el envío queda
     * una parada más cerca del cliente. No baja de cero ni graba evento (es un ajuste de proyección de
     * alta frecuencia, no un hecho de negocio nuevo).
     */
    public function decrementStops(): void
    {
        if ($this->remainingStops === 0) {
            return;
        }

        $this->remainingStops--;
    }

    public function id(): TrackingId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function token(): TrackingToken
    {
        return $this->token;
    }

    public function status(): VisibleStatus
    {
        return $this->status;
    }

    public function remainingStops(): int
    {
        return $this->remainingStops;
    }

    public function eta(): ?Eta
    {
        return $this->eta;
    }

    public function lastPosition(): ?TruckPosition
    {
        return $this->lastPosition;
    }

    public function routeId(): ?string
    {
        return $this->routeId;
    }

    public function routePosition(): ?RoutePosition
    {
        return $this->routePosition;
    }
}
