<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Model;

use DateTimeImmutable;
use Logistics\Promise\Domain\Event\PromiseConfirmed;
use Logistics\Promise\Domain\Event\PromiseExpired;
use Logistics\Promise\Domain\Event\PromiseReserved;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Promise\Domain\ValueObject\PromiseStatus;
use Logistics\Promise\Domain\ValueObject\ReservationWindow;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Agregado raíz del contexto: la promesa de entrega de un envío hacia un canal, en una franja concreta.
 *
 * Nace cotizada (`quote()`), se reserva el cupo por una ventana de tiempo (`reserve()`), y luego o bien
 * se confirma dentro de la ventana (`confirm()`) o bien expira y se libera el cupo (`expire()`). La
 * referencia al envío y la zona se guardan como primitivos (`shipmentRef`, `zoneId`): este contexto no
 * importa shipments ni coverage, solo conoce sus ids.
 */
final class DeliveryPromise extends AggregateRoot
{
    private function __construct(
        private readonly PromiseId $id,
        private readonly string $shipmentRef,
        private readonly string $zoneId,
        private readonly DeliverySlot $slot,
        private PromiseStatus $status,
        private ?ReservationWindow $reservedUntil,
    ) {}

    /**
     * Cotiza una promesa: la deja ofrecida (Quoted) para una franja, sin reservar cupo todavía.
     * Cotizar no es un hecho que viaje al outbox, así que no graba evento.
     */
    public static function quote(
        PromiseId $id,
        string $shipmentRef,
        string $zoneId,
        DeliverySlot $slot,
    ): self {
        $trimmedShipmentRef = trim($shipmentRef);
        if ($trimmedShipmentRef === '') {
            throw PromiseException::because('la referencia del envío no puede estar vacía');
        }

        $trimmedZoneId = trim($zoneId);
        if ($trimmedZoneId === '') {
            throw PromiseException::because('la zona no puede estar vacía');
        }

        return new self(
            $id,
            $trimmedShipmentRef,
            $trimmedZoneId,
            $slot,
            PromiseStatus::Quoted,
            null,
        );
    }

    /**
     * Reconstituye el agregado desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     */
    public static function reconstitute(
        PromiseId $id,
        string $shipmentRef,
        string $zoneId,
        DeliverySlot $slot,
        PromiseStatus $status,
        ?ReservationWindow $reservedUntil,
    ): self {
        return new self($id, $shipmentRef, $zoneId, $slot, $status, $reservedUntil);
    }

    /**
     * Reserva el cupo por la ventana dada. Transición válida solo desde Quoted. Graba PromiseReserved.
     */
    public function reserve(ReservationWindow $window): void
    {
        if ($this->status !== PromiseStatus::Quoted) {
            throw PromiseException::because("solo se puede reservar una promesa cotizada; estado actual {$this->status->value}");
        }

        $this->status = PromiseStatus::Reserved;
        $this->reservedUntil = $window;

        $this->recordEvent(new PromiseReserved(
            $this->id->toString(),
            $this->shipmentRef,
            $this->zoneId,
            $this->slot->dateString(),
            $this->slot->timeSlot,
            $window->expiresAt->format(DateTimeImmutable::ATOM),
        ));
    }

    /**
     * Confirma la promesa si la ventana de reserva no venció al instante `$now`. Transición válida solo
     * desde Reserved. Graba PromiseConfirmed.
     */
    public function confirm(DateTimeImmutable $now): void
    {
        if ($this->status !== PromiseStatus::Reserved) {
            throw PromiseException::because("solo se puede confirmar una promesa reservada; estado actual {$this->status->value}");
        }
        if ($this->reservedUntil !== null && $this->reservedUntil->isExpiredAt($now)) {
            throw PromiseException::because('la ventana de reserva venció; no se puede confirmar');
        }

        $this->status = PromiseStatus::Confirmed;

        $this->recordEvent(new PromiseConfirmed(
            $this->id->toString(),
            $this->shipmentRef,
            $this->zoneId,
            $this->slot->dateString(),
            $this->slot->timeSlot,
        ));
    }

    /**
     * Expira la reserva y libera el cupo. Transición válida desde Reserved (una promesa confirmada ya
     * no expira). Graba PromiseExpired.
     */
    public function expire(): void
    {
        if ($this->status !== PromiseStatus::Reserved) {
            throw PromiseException::because("solo expira una promesa reservada; estado actual {$this->status->value}");
        }

        $this->status = PromiseStatus::Expired;

        $this->recordEvent(new PromiseExpired(
            $this->id->toString(),
            $this->shipmentRef,
            $this->zoneId,
            $this->slot->dateString(),
            $this->slot->timeSlot,
        ));
    }

    public function id(): PromiseId
    {
        return $this->id;
    }

    public function shipmentRef(): string
    {
        return $this->shipmentRef;
    }

    public function zoneId(): string
    {
        return $this->zoneId;
    }

    public function slot(): DeliverySlot
    {
        return $this->slot;
    }

    public function status(): PromiseStatus
    {
        return $this->status;
    }

    public function reservedUntil(): ?ReservationWindow
    {
        return $this->reservedUntil;
    }
}
