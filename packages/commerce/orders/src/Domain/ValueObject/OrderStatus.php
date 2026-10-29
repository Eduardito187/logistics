<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\ValueObject;

/**
 * Estado del ciclo de vida de una orden comercial.
 *
 * - Pending: creada en checkout, esperando pago.
 * - Paid: pago confirmado (evento `payments.captured` → `orders.order_paid`).
 * - Fulfilling: en fulfillment (se creó el envío en la logística aguas abajo).
 * - Cancelled: cancelada (solo posible mientras estaba Pending).
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Fulfilling = 'fulfilling';
    case Cancelled = 'cancelled';
}
