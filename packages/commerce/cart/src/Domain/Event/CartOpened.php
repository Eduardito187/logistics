<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se abrió un carrito nuevo. El dueño (customer) es opcional: null = carrito de invitado, identificado
 * por su session_id.
 */
final readonly class CartOpened extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public ?string $customerId,
        public string $sessionId,
        public string $currency,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'cart.opened';
    }

    public function payload(): array
    {
        return [
            'customer_id' => $this->customerId,
            'session_id' => $this->sessionId,
            'currency' => $this->currency,
        ];
    }
}
