<?php

declare(strict_types=1);

namespace Commerce\Cart\Infrastructure\Persistence\Eloquent\Repository;

use Commerce\Cart\Domain\Contract\CartRepository;
use Commerce\Cart\Domain\Model\Cart;
use Commerce\Cart\Domain\ValueObject\CartId;
use Commerce\Cart\Domain\ValueObject\CartStatus;
use Commerce\Cart\Infrastructure\Persistence\Eloquent\Mapper\CartMapper;
use Commerce\Cart\Infrastructure\Persistence\Eloquent\Model\CartRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del repositorio de carritos. `save` es un upsert por id (las líneas viajan en
 * la misma fila como JSONB). Las búsquedas por sesión/cliente devuelven el carrito ABIERTO más reciente.
 */
final readonly class EloquentCartRepository implements CartRepository
{
    public function __construct(
        private CartMapper $mapper = new CartMapper,
    ) {}

    public function nextIdentity(): CartId
    {
        return CartId::generate();
    }

    public function save(Cart $cart): void
    {
        $row = $this->mapper->toRow($cart);
        CartRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(CartId $id): ?Cart
    {
        $record = CartRecord::query()->find($id->toString());

        return $record instanceof CartRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findBySession(string $sessionId): ?Cart
    {
        $record = CartRecord::query()
            ->where('session_id', $sessionId)
            ->where('status', CartStatus::Open->value)
            ->latest('updated_at')
            ->first();

        return $record instanceof CartRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findOpenByCustomer(Uuid $customerId): ?Cart
    {
        $record = CartRecord::query()
            ->where('customer_id', $customerId->value)
            ->where('status', CartStatus::Open->value)
            ->latest('updated_at')
            ->first();

        return $record instanceof CartRecord ? $this->mapper->toDomain($record) : null;
    }
}
