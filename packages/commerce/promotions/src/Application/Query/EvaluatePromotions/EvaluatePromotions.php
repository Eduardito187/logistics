<?php

declare(strict_types=1);

namespace Commerce\Promotions\Application\Query\EvaluatePromotions;

use Logistics\Shared\Application\Query\Query;

/**
 * Evalúa el descuento aplicable a un subtotal dado, en un canal, con (opcional) un código de cupón.
 *
 * - subtotalCents: subtotal de productos en centavos.
 * - channel: Retail | B2B | C2C.
 * - couponCode: código de cupón ingresado por el cliente; null = sin cupón (solo promos automáticas).
 */
final readonly class EvaluatePromotions implements Query
{
    public function __construct(
        public int $subtotalCents,
        public string $channel,
        public ?string $couponCode = null,
    ) {}
}
