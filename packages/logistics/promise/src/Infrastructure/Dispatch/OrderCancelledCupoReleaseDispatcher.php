<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Dispatch;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Promise\Application\Command\ReleasePromiseReservation\ReleasePromiseReservation;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reactor orders → promise (ADR 020): ante `orders.order_cancelled` (cualquier etapa, pre o post pago,
 * venga de POS / manual / cron de expiración), LIBERA el cupo que la orden tenía tomado y lo devuelve a
 * capacity. Cubre tanto promesas Reserved (aún sin pagar) como Confirmed (ya pagadas), por eso usa
 * ReleasePromiseReservation y no ExpireReservation —esta última solo aplica al vencimiento desde Reserved—.
 *
 * Adaptador de INTEGRACIÓN (capa Infrastructure, anti-corruption): orquesta el contexto promise SOLO por
 * su Command de Application a través del CommandBus. El cupo se ató a la orden por `shipment_ref = order_id`
 * al reservarlo, así que se recupera por esa referencia.
 *
 * Idempotente y tolerante a reentregas (at-least-once): si la orden no reservó cupo, no hay nada que
 * liberar; ReleasePromiseReservation es no-op sobre promesas ya terminales, así una reentrega del evento
 * no libera capacidad dos veces.
 */
final readonly class OrderCancelledCupoReleaseDispatcher implements MessageDispatcher
{
    public function __construct(
        private PromiseRepository $promises,
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'orders.order_cancelled') {
            return;
        }

        $orderId = $this->orderId($message);
        if ($orderId === null) {
            return;
        }

        foreach ($this->promises->holdingByShipmentRef($orderId) as $promiseId) {
            $this->commandBus->dispatch(new ReleasePromiseReservation($promiseId->toString()));
        }
    }

    /** Id de la orden: el payload de order_cancelled lo lleva como `order_id` (= aggregateId). */
    private function orderId(OutboxMessage $message): ?string
    {
        $payload = $message->payload();
        $fromPayload = $payload['order_id'] ?? null;
        if (is_string($fromPayload) && $fromPayload !== '') {
            return $fromPayload;
        }

        $aggregateId = $message->aggregateId();

        return $aggregateId !== '' ? $aggregateId : null;
    }
}
