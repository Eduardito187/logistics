<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\Contract;

use Commerce\Cart\Domain\Model\Cart;
use Commerce\Cart\Domain\ValueObject\CartId;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Puerto de persistencia de carritos. La implementación vive en Infrastructure.
 */
interface CartRepository
{
    public function nextIdentity(): CartId;

    /**
     * Upsert del carrito por id (incluye sus líneas como JSONB en la misma fila).
     */
    public function save(Cart $cart): void;

    public function findById(CartId $id): ?Cart;

    /**
     * Carrito abierto de una sesión del storefront (carrito de invitado), o null.
     */
    public function findBySession(string $sessionId): ?Cart;

    /**
     * Carrito abierto de un cliente identificado, o null.
     */
    public function findOpenByCustomer(Uuid $customerId): ?Cart;
}
