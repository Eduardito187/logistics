<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\PlaceOrderFromCart;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención del checkout orquestado: crear una orden a partir de un carrito EXISTENTE (líneas con
 * precio congelado) más los datos de entrega `ship_to`. El handler lee la instantánea del carrito; la
 * conversión del carrito ocurre DESACOPLADA por outbox (no hay llamada síncrona orders → cart).
 */
final readonly class PlaceOrderFromCart implements Command
{
    public function __construct(
        public string $cartId,
        public string $customerName,
        public string $addressText,
        public ?string $addressReference = null,
        public ?string $phone = null,
        public ?string $email = null,
    ) {}
}
