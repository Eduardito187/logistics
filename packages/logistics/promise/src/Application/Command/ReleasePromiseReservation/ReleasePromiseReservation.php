<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ReleasePromiseReservation;

use Logistics\Shared\Application\Command\Command;

/**
 * Libera el cupo reservado por una CANCELACIÓN de la orden (ADR 020), a diferencia de ExpireReservation
 * que lo libera por vencimiento de la ventana. Aplica tanto a promesas Reserved como Confirmed, porque
 * una orden puede cancelarse en cualquier etapa (pre y post pago). Lo dispara el puente
 * orders.order_cancelled → promise (OrderCancelledCupoReleaseDispatcher).
 */
final readonly class ReleasePromiseReservation implements Command
{
    public function __construct(
        public string $promiseId,
    ) {}
}
