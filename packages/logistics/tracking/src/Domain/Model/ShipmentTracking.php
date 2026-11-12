<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Model;

use DateInterval;
use DateTimeImmutable;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Tracking\Domain\Event\ShipmentPlacedOnRoute;
use Logistics\Tracking\Domain\Event\TrackingOpened;
use Logistics\Tracking\Domain\Event\TrackingShipmentBound;
use Logistics\Tracking\Domain\Event\TrackingStatusChanged;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\Eta;
use Logistics\Tracking\Domain\ValueObject\RoutePosition;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\TruckPosition;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Agregado raíz del contexto de seguimiento: el estado visible del PEDIDO para el cliente, como un ciclo
 * de vida único (pendiente → procesando → en curso → cargada → en camino → entregado, con
 * cancelación/reprogramación/devolución).
 *
 * Nace al crear la orden (por `orderReference`, estado pendiente, aún sin envío); cuando el pago crea el
 * envío se le liga su `shipmentId` (`bindShipment`). Es principalmente una proyección, pero se modela como
 * agregado para GOBERNAR LAS REGLAS: `applyStatus()` solo deja transiciones admitidas (ver
 * {@see VisibleStatus::canTransitionTo()}) — nada de saltos — y nunca guarda datos personales. Referencia a
 * orden/envío por id string, nunca importando el agregado de otro contexto.
 */
final class ShipmentTracking extends AggregateRoot
{
    private function __construct(
        private readonly TrackingId $id,
        private ?string $shipmentId,
        private readonly TrackingToken $token,
        private VisibleStatus $status,
        private int $remainingStops,
        private ?Eta $eta,
        private ?TruckPosition $lastPosition,
        private ?string $routeId,
        private ?RoutePosition $routePosition,
        private readonly ?string $orderReference = null,
        private ?DateTimeImmutable $deliveredAt = null,
    ) {}

    /**
     * Abre el seguimiento de un PEDIDO: estado inicial Pending, aún sin envío ligado (shipmentId null),
     * token público recién generado. Graba TrackingOpened. Se identifica por `orderReference`.
     */
    public static function open(
        TrackingId $id,
        string $orderReference,
        TrackingToken $token,
        int $remainingStops = 0,
    ): self {
        $orderReference = trim($orderReference);
        if ($orderReference === '') {
            throw TrackingException::because('la referencia de la orden no puede estar vacía');
        }
        if ($remainingStops < 0) {
            throw TrackingException::because("las paradas restantes no pueden ser negativas: {$remainingStops}");
        }

        $tracking = new self(
            $id,
            null,
            $token,
            VisibleStatus::Pending,
            $remainingStops,
            null,
            null,
            null,
            null,
            $orderReference,
        );

        $tracking->recordEvent(new TrackingOpened(
            $id->toString(),
            null,
            $token->toString(),
            VisibleStatus::Pending->value,
            $remainingStops,
            $orderReference,
        ));

        return $tracking;
    }

    /**
     * Reconstituye el agregado desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     */
    public static function reconstitute(
        TrackingId $id,
        ?string $shipmentId,
        TrackingToken $token,
        VisibleStatus $status,
        int $remainingStops,
        ?Eta $eta,
        ?TruckPosition $lastPosition,
        ?string $routeId = null,
        ?RoutePosition $routePosition = null,
        ?string $orderReference = null,
        ?DateTimeImmutable $deliveredAt = null,
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
            $orderReference,
            $deliveredAt,
        );
    }

    /**
     * Liga el envío real (su id interno) al seguimiento del pedido cuando el pago lo crea. Idempotente:
     * reatar el mismo envío no hace nada; graba TrackingShipmentBound la primera vez.
     */
    public function bindShipment(string $shipmentId): void
    {
        $shipmentId = trim($shipmentId);
        if ($shipmentId === '') {
            throw TrackingException::because('el id del envío a ligar no puede estar vacío');
        }
        if ($this->shipmentId === $shipmentId) {
            return;
        }
        if ($this->shipmentId !== null) {
            throw TrackingException::because(
                "el seguimiento {$this->id->toString()} ya está ligado a otro envío; no se puede reasignar",
            );
        }

        $this->shipmentId = $shipmentId;

        $this->recordEvent(new TrackingShipmentBound(
            $this->id->toString(),
            $shipmentId,
            $this->orderReference,
        ));
    }

    /**
     * ¿Es seguro aplicar este estado? True si es el mismo (idempotente) o una transición admitida. Lo usan
     * los reactores de la proyección para SALTEAR (no romper) un evento tardío/duplicado del outbox.
     */
    public function canApply(VisibleStatus $status): bool
    {
        return $this->status === $status || $this->status->canTransitionTo($status);
    }

    /**
     * Aplica un nuevo estado visible respetando la máquina de estados. Idempotente: si no cambia, no graba
     * evento. Rechaza con excepción cualquier transición NO admitida (regla dura: sin saltos en el flujo).
     *
     * `$at` es el momento del hecho que provoca el cambio (p. ej. el `occurred_at` de la entrega). Al pasar a
     * ENTREGADO fija `deliveredAt` (la primera vez; un reenvío no lo mueve). Si el seguimiento ya estaba
     * entregado sin fecha (filas previas a la columna), el reenvío del hecho la completa. Sin `$at` se usa
     * el momento actual.
     */
    public function applyStatus(VisibleStatus $status, ?DateTimeImmutable $at = null): void
    {
        if ($this->status === $status) {
            if ($status === VisibleStatus::Delivered && $this->deliveredAt === null && $at !== null) {
                $this->deliveredAt = $at;
            }

            return;
        }
        if (!$this->status->canTransitionTo($status)) {
            throw TrackingException::because(
                "transición no admitida: {$this->status->value} → {$status->value}",
            );
        }

        $previous = $this->status;
        $this->status = $status;

        if ($status === VisibleStatus::Delivered && $this->deliveredAt === null) {
            $this->deliveredAt = $at ?? new DateTimeImmutable;
        }

        $this->recordEvent(new TrackingStatusChanged(
            $this->id->toString(),
            $this->shipmentId,
            $previous->value,
            $status->value,
            $this->orderReference,
        ));
    }

    /** Pago confirmado: pendiente → procesando. */
    public function markProcessing(): void
    {
        $this->applyStatus(VisibleStatus::Processing);
    }

    /** Etiqueta generada: procesando → en curso. */
    public function markInProgress(): void
    {
        $this->applyStatus(VisibleStatus::InProgress);
    }

    /** Cargada en el camión. */
    public function markLoaded(): void
    {
        $this->applyStatus(VisibleStatus::Loaded);
    }

    /** El camión salió: en camino. */
    public function markOnTheWay(): void
    {
        $this->applyStatus(VisibleStatus::OnTheWay);
    }

    /** Entregada al cliente (en `$at`, o ahora si no se informa). */
    public function markDelivered(?DateTimeImmutable $at = null): void
    {
        $this->applyStatus(VisibleStatus::Delivered, $at);
    }

    /** Intento de entrega fallido. */
    public function markFailed(): void
    {
        $this->applyStatus(VisibleStatus::Failed);
    }

    /** Anulada (sin pago, o anulada tras el pago antes de entregar). */
    public function cancel(): void
    {
        $this->applyStatus(VisibleStatus::Cancelled);
    }

    /** Reprogramada: cambió la fecha/lugar de entrega (tras el pago, antes de entregar). */
    public function reschedule(): void
    {
        $this->applyStatus(VisibleStatus::Rescheduled);
    }

    /** Devuelta por el cliente tras la entrega. */
    public function markReturned(): void
    {
        $this->applyStatus(VisibleStatus::Returned);
    }

    /**
     * Actualiza la posición en vivo del camión. No graba evento de dominio: es un dato de alta
     * frecuencia (telemetría), no un hecho de negocio.
     *
     * Robustez ante telemetría real: la ingesta llega a ~3 req/s y puede desordenarse (buffer del
     * dispositivo offline, reintentos del relay, varios reportes casi simultáneos). La proyección es
     * MONÓTONA: un reporte más viejo que el último conocido se descarta, para no "teletransportar" el
     * camión hacia atrás en el mapa. Devuelve true si la posición se aplicó, false si se descartó.
     */
    public function updatePosition(TruckPosition $position): bool
    {
        if ($this->lastPosition !== null
            && $position->reportedAt < $this->lastPosition->reportedAt
        ) {
            return false;
        }

        $this->lastPosition = $position;

        return true;
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
        if ($this->shipmentId === null) {
            throw TrackingException::because('no se puede ubicar en ruta un seguimiento sin envío ligado');
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

    public function shipmentId(): ?string
    {
        return $this->shipmentId;
    }

    public function orderReference(): ?string
    {
        return $this->orderReference;
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

    /** Momento de la entrega al cliente; null si todavía no se entregó (o fila previa sin backfill). */
    public function deliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    /**
     * Vencimiento del ENLACE PÚBLICO de seguimiento (/t/{token} y sus endpoints públicos).
     *
     * Regla: el TTL (`tracking.public_link_ttl_hours`) se cuenta DESDE LA ENTREGA (`deliveredAt`). Mientras
     * el pedido no se entregó, el enlace NO vence: el cliente lo necesita durante el ciclo entero (un pedido
     * agendado a 5 días no puede perder su seguimiento a las 72 h de creado). Una devolución posterior a la
     * entrega conserva `deliveredAt`, así que sigue contando desde la entrega. Un TTL ≤ 0 desactiva el
     * vencimiento. Devuelve null cuando el enlace no vence.
     */
    public function publicLinkExpiresAt(int $ttlHours): ?DateTimeImmutable
    {
        if ($ttlHours <= 0 || $this->deliveredAt === null) {
            return null;
        }

        return $this->deliveredAt->add(new DateInterval('PT'.$ttlHours.'H'));
    }

    /** ¿El enlace público ya venció en `$now`? (ver {@see publicLinkExpiresAt()}). */
    public function isPublicLinkExpiredAt(DateTimeImmutable $now, int $ttlHours): bool
    {
        $expiresAt = $this->publicLinkExpiresAt($ttlHours);

        return $expiresAt !== null && $now >= $expiresAt;
    }
}
