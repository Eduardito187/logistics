<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se recibió físicamente la devolución en el almacén central: la mercadería ya está en poder de Dismac,
 * pendiente de decidir su disposición (restock / devolver al seller / descartar).
 */
final readonly class ReturnReceived extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $warehouseId,
        public ?string $ownerPartyId,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'returns.return_received';
    }

    public function payload(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'owner_party_id' => $this->ownerPartyId,
        ];
    }
}
