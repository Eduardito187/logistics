<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se cobró la cuota inicial de una orden de crédito con inicial. Hecho reportado por el sistema de
 * crédito/POS. Junto a la firma del contrato, habilita el paso a procesando.
 */
final readonly class OrderInitialPaid extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public ?string $reference = null,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'orders.order_initial_paid';
    }

    public function payload(): array
    {
        return [
            'reference' => $this->reference,
        ];
    }
}
