<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se activó un seller: queda habilitado a operar (recibir mercadería, atribuirle inventario).
 */
final readonly class SellerActivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'sellers.seller_activated';
    }

    public function payload(): array
    {
        return [];
    }
}
