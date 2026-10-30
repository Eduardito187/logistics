<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El carrito se convirtió en orden. Es el punto de convergencia comercio → OMS (ADR 018): `orders`
 * consumirá este hecho vía outbox para crear la orden, SIN conocer el Domain de `cart`.
 *
 * Por eso el payload lleva una instantánea completa y primitiva del carrito (dueño, sesión, moneda,
 * líneas con subtotales y total en centavos): el consumidor proyecta la orden sin volver a consultar.
 */
final readonly class CartConverted extends BaseDomainEvent
{
    /**
     * @param list<array{sku: string, product_name: string, unit_price_cents: int, qty: int, subtotal_cents: int}> $lines
     */
    public function __construct(
        string $aggregateId,
        public ?string $customerId,
        public string $sessionId,
        public string $currency,
        public array $lines,
        public int $totalCents,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'cart.converted';
    }

    public function payload(): array
    {
        return [
            'customer_id' => $this->customerId,
            'session_id' => $this->sessionId,
            'currency' => $this->currency,
            'lines' => $this->lines,
            'total_cents' => $this->totalCents,
        ];
    }
}
