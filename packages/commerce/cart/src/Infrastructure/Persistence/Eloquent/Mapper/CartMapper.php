<?php

declare(strict_types=1);

namespace Commerce\Cart\Infrastructure\Persistence\Eloquent\Mapper;

use Commerce\Cart\Domain\Model\Cart;
use Commerce\Cart\Domain\ValueObject\CartId;
use Commerce\Cart\Domain\ValueObject\CartLine;
use Commerce\Cart\Domain\ValueObject\CartStatus;
use Commerce\Cart\Infrastructure\Persistence\Eloquent\Model\CartRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Traduce entre el agregado Cart (dominio) y su fila Eloquent. Único punto que conoce ambos lados.
 */
final class CartMapper
{
    public function toDomain(CartRecord $record): Cart
    {
        return Cart::reconstitute(
            CartId::fromString($record->id),
            $record->customer_id !== null ? Uuid::fromString($record->customer_id) : null,
            $record->session_id,
            CartStatus::from($record->status),
            $record->currency,
            array_values(array_map(
                static fn (array $line): CartLine => CartLine::fromArray($line),
                $record->lines,
            )),
        );
    }

    /**
     * @return array{id: string, customer_id: ?string, session_id: string, status: string, currency: string, lines: list<array{sku: string, product_name: string, unit_price_cents: int, qty: int, subtotal_cents: int}>}
     */
    public function toRow(Cart $cart): array
    {
        return [
            'id' => $cart->id()->toString(),
            'customer_id' => $cart->customerId()?->value,
            'session_id' => $cart->sessionId(),
            'status' => $cart->status()->value,
            'currency' => $cart->currency(),
            'lines' => array_values(array_map(
                static fn (CartLine $line): array => $line->toArray(),
                $cart->lines(),
            )),
        ];
    }
}
