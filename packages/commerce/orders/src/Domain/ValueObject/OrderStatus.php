<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Estado del ciclo de vida de una orden comercial.
 *
 * - Pending: creada en checkout, esperando pago.
 * - Paid: pago confirmado (evento `payments.captured` → `orders.order_paid`).
 * - Fulfilling: en fulfillment (se creó el envío en la logística aguas abajo).
 * - Cancelled: cancelada (único estado terminal; se puede cancelar desde Pending, Paid o Fulfilling, ADR 020).
 */
enum OrderStatus: string
{
    use CaseInsensitiveEnum;

    case Pending = 'pending';
    case Paid = 'paid';
    case Fulfilling = 'fulfilling';
    case Cancelled = 'cancelled';
}
