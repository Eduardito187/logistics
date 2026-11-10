<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Dispatch;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Promise\Application\Command\ConfirmPromise\ConfirmPromise;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reactor orders → promise (ADR 020): ante `orders.order_paid`, CONFIRMA el cupo que la orden tenía
 * reservado antes de pagar. Es clave para la regla de negocio: una promesa Reserved la libera el barrido
 * de vencimiento (logistics:promise:expire-reservations) cuando su ventana caduca; al confirmarla, el
 * cupo de una orden YA PAGADA queda en firme y deja de estar sujeto a esa expiración automática.
 *
 * Adaptador de INTEGRACIÓN (capa Infrastructure, anti-corruption): orquesta el contexto promise SOLO por
 * su Command de Application a través del CommandBus, nunca toca su dominio. El cupo se ató a la orden por
 * `shipment_ref = order_id` al reservarlo (ver docs/adr/020), así que se recupera por esa referencia.
 *
 * Idempotente y tolerante a reentregas (at-least-once): si la orden no reservó cupo, no hay nada que
 * confirmar; si la promesa ya está Confirmed (reentrega) o su ventana venció, ConfirmPromise lanza y se
 * ignora —confirmar dos veces no debe tumbar el procesamiento del mensaje—.
 */
final readonly class OrderPaidCupoConfirmDispatcher implements MessageDispatcher
{
    public function __construct(
        private PromiseRepository $promises,
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'orders.order_paid') {
            return;
        }

        $orderId = $this->orderId($message);
        if ($orderId === null) {
            return;
        }

        foreach ($this->promises->holdingByShipmentRef($orderId) as $promiseId) {
            try {
                $this->commandBus->dispatch(new ConfirmPromise($promiseId->toString()));
            } catch (PromiseException) {
                // Promesa ya confirmada (reentrega) o con ventana vencida: no es motivo para fallar el
                // mensaje. El cupo vencido ya se habrá liberado por el barrido; nada que confirmar.
            }
        }
    }

    /** Id de la orden: el payload de order_paid no lo lleva explícito, así que es el aggregateId. */
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
