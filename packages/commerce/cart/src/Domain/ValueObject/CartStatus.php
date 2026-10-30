<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\ValueObject;

/**
 * Estado del carrito.
 *
 * - Open: en uso, admite cambios de líneas.
 * - Converted: ya dio lugar a una orden; inmutable (hecho `cart.converted` emitido para `orders`).
 * - Abandoned: descartado sin convertir; se conserva el histórico.
 */
enum CartStatus: string
{
    case Open = 'open';
    case Converted = 'converted';
    case Abandoned = 'abandoned';
}
