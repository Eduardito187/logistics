<?php

declare(strict_types=1);

namespace Commerce\Cart\Application\ReadModel;

use Commerce\Cart\Domain\Model\Cart;
use Commerce\Cart\Domain\ValueObject\CartLine;

/**
 * Proyección de lectura de un carrito para API y storefront. Primitivos: no expone dominio. Incluye el
 * total en centavos ya calculado para evitar recomputarlo del lado del consumidor.
 */
final readonly class CartView
{
    /**
     * @param list<array{sku: string, product_name: string, unit_price_cents: int, qty: int, subtotal_cents: int}> $lines
     */
    private function __construct(
        public string $id,
        public ?string $customerId,
        public string $sessionId,
        public string $status,
        public string $currency,
        public array $lines,
        public int $totalCents,
    ) {}

    public static function fromDomain(Cart $cart): self
    {
        return new self(
            $cart->id()->toString(),
            $cart->customerId()?->value,
            $cart->sessionId(),
            $cart->status()->value,
            $cart->currency(),
            array_map(static fn (CartLine $line): array => $line->toArray(), $cart->lines()),
            $cart->totalCents(),
        );
    }

    /**
     * @return array{id: string, customer_id: ?string, session_id: string, status: string, currency: string, lines: list<array{sku: string, product_name: string, unit_price_cents: int, qty: int, subtotal_cents: int}>, total_cents: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'session_id' => $this->sessionId,
            'status' => $this->status,
            'currency' => $this->currency,
            'lines' => $this->lines,
            'total_cents' => $this->totalCents,
        ];
    }
}
