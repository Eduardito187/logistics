<?php

declare(strict_types=1);

namespace Commerce\Payments\Infrastructure\Dispatch;

use Commerce\Payments\Application\Command\RefundPayment\RefundPayment;
use Commerce\Payments\Application\Query\GetPaymentByOrder\GetPaymentByOrder;
use Commerce\Payments\Application\ReadModel\PaymentView;
use Commerce\Payments\Domain\ValueObject\PaymentStatus;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puente orders → payments por outbox (ADR 020, decisión 3): cuando se cancela una orden que YA estaba
 * pagada (`orders.order_cancelled` con `was_paid = true`), dispara el reembolso del cobro.
 *
 * Consume el evento por su NOMBRE y PAYLOAD, sin `use` de clases de orders (cada contexto queda
 * extraíble a microservicio): el pago se resuelve con la consulta propia `GetPaymentByOrder`.
 *
 * Idempotente y tolerante a reentregas (at-least-once):
 *  - Si la orden no estaba pagada (`was_paid` false/ausente) → no-op.
 *  - Si no existe pago para la orden → no-op (condición esperable, no se manda a dead-letter).
 *  - Si el pago no es reembolsable (no está capturado ni ya reembolsado) → degrada sin romper el relay.
 *  - Si ya está reembolsado → no-op (además `RefundPayment` es idempotente en el agregado).
 */
final readonly class OrderCancelledRefundDispatcher implements MessageDispatcher
{
    public function __construct(
        private QueryBus $queryBus,
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'orders.order_cancelled') {
            return;
        }

        $payload = $message->payload();

        // Solo reembolsamos si la orden estaba pagada; si no, nada que reversar.
        if (($payload['was_paid'] ?? null) !== true) {
            return;
        }

        $orderId = $payload['order_id'] ?? null;
        if (!is_string($orderId) || $orderId === '') {
            return;
        }

        // Resuelve el pago de la orden por la consulta del propio contexto (read-side).
        $payment = $this->queryBus->ask(new GetPaymentByOrder($orderId));
        if (!$payment instanceof PaymentView) {
            // Sin pago registrado: condición esperable (no se relanza, no va a dead-letter).
            return;
        }

        // Solo un cobro capturado es reembolsable. Si ya está reembolsado o en cualquier otro estado
        // (pendiente, fallido, ...), degradamos sin disparar el comando: evitamos la excepción del
        // agregado que mandaría el mensaje a dead-letter por una condición esperable.
        if ($payment->status !== PaymentStatus::Captured->value) {
            return;
        }

        // RefundPayment es idempotente en el agregado (si ya está Refunded, no re-graba).
        $this->commandBus->dispatch(new RefundPayment($payment->id));
    }
}
