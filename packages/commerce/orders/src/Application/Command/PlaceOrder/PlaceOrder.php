<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\Command\PlaceOrder;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de crear una orden en el checkout, a partir de una instantánea del carrito (líneas con
 * precio congelado) y los datos de entrega.
 */
final readonly class PlaceOrder implements Command
{
    /**
     * @param list<array{sku: string, product_name: string, unit_price_cents: int, quantity: int}> $lines
     */
    public function __construct(
        public string $customerName,
        public string $addressText,
        public array $lines,
        public ?string $customerId = null,
        public ?string $addressReference = null,
        public ?string $phone = null,
        public ?string $email = null,
        public string $currency = 'BOB',
    ) {}
}
