<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Dispatch;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Promise\Application\Command\ReserveDeliveryDate\ReserveDeliveryDate;
use Logistics\Promise\Domain\Contract\OrderDeliverySlotGateway;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Base de los reactores de auto-slotting por OUTBOX (Módulo 1, Fase 1.1): ante el evento que cada subclase
 * declara, si el envío trae una fecha de entrega SIN ventana, pide que el sistema le asigne la ventana
 * (`ReserveDeliveryDate`). Es el disparo automático que convierte "el cliente solo eligió fecha" en una
 * franja comprometida, sin que el canal tenga que elegirla.
 *
 * Adaptador de INTEGRACIÓN (capa Infrastructure, anti-corruption): orquesta el contexto promise SOLO por su
 * Command de Application a través del CommandBus, nunca toca su dominio. El cruce a shipments para leer la
 * fecha pendiente es por el puerto `OrderDeliverySlotGateway` y por primitivos.
 *
 * Idempotente y tolerante a reentregas (at-least-once): si el envío no tiene fecha, o ya tiene ventana, el
 * puerto devuelve null y no se hace nada; `ReserveDeliveryDate` es a su vez idempotente por (envío, fecha).
 * Si el día no es despachable o no hay ventana factible, el comando lanza y el reactor lo traga: el envío
 * queda a la vista en la cola "Unslotted" (ListUnslottedShipments); NO se reintenta el mensaje por eso, que
 * no es un fallo de entrega sino una decisión de operaciones.
 */
abstract readonly class AutoSlotDispatcher implements MessageDispatcher
{
    public function __construct(
        private OrderDeliverySlotGateway $orders,
        private CommandBus $commandBus,
    ) {}

    /** Nombre EXACTO del evento al que reacciona esta subclase. */
    abstract protected function eventName(): string;

    /** Clave del payload que lleva el id del envío/pedido (el resto cae al aggregateId). */
    abstract protected function payloadIdKey(): string;

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== $this->eventName()) {
            return;
        }

        $shipmentId = $this->shipmentId($message);
        if ($shipmentId === null) {
            return;
        }

        $date = $this->orders->pendingDeliveryDate($shipmentId);
        if ($date === null) {
            // Sin fecha solicitada o ya con ventana: nada que slotear (idempotente ante reentregas).
            return;
        }

        try {
            $this->commandBus->dispatch(new ReserveDeliveryDate($shipmentId, $date));
        } catch (PromiseException) {
            // Día no despachable / sin ventana con cupo factible: el envío queda en la cola "Unslotted"
            // para que operaciones lo resuelva. Tragar la excepción evita reintentos inútiles del mensaje.
        }
    }

    /** Id del envío: la clave del payload de la subclase o, en su defecto, el aggregateId del mensaje. */
    private function shipmentId(OutboxMessage $message): ?string
    {
        $payload = $message->payload();
        $fromPayload = $payload[$this->payloadIdKey()] ?? null;
        if (is_string($fromPayload) && $fromPayload !== '') {
            return $fromPayload;
        }

        $aggregateId = $message->aggregateId();

        return $aggregateId !== '' ? $aggregateId : null;
    }
}
