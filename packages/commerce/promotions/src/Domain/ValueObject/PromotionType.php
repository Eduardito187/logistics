<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\ValueObject;

/**
 * Tipo de beneficio que otorga una promoción.
 *
 * - PercentageOff: descuento porcentual; el valor se guarda en basis points (0..10000, 10000 = 100%).
 * - FixedAmountOff: descuento de un monto fijo; el valor se guarda en centavos de la moneda.
 * - FreeShipping: envío gratis; no descuenta sobre el subtotal de productos (el ahorro lo resuelve checkout).
 */
enum PromotionType: string
{
    case PercentageOff = 'percentage_off';
    case FixedAmountOff = 'fixed_amount_off';
    case FreeShipping = 'free_shipping';
}
