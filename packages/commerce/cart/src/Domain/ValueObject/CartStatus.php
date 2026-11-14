<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Estado del carrito.
 *
 * - Open: en uso, admite cambios de líneas.
 * - Converted: ya dio lugar a una orden; inmutable (hecho `cart.converted` emitido para `orders`).
 * - Abandoned: descartado sin convertir; se conserva el histórico.
 */
enum CartStatus: string
{
    use CaseInsensitiveEnum;

    case Open = 'open';
    case Converted = 'converted';
    case Abandoned = 'abandoned';
}
