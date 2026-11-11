<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se firmó el contrato de crédito/minicuotas de la orden. Hecho reportado por el sistema de crédito/POS.
 * Habilita el paso a procesando (solo contrato, si el crédito no lleva inicial; junto al inicial, si lo lleva).
 */
final readonly class OrderContractSigned extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public ?string $reference = null,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'orders.order_contract_signed';
    }

    public function payload(): array
    {
        return [
            'reference' => $this->reference,
        ];
    }
}
