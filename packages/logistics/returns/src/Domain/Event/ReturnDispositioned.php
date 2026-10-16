<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se decidió la disposición de una devolución recibida (restock / devolver al seller / descartar).
 *
 * Es el evento que cierra la logística inversa y el punto de enganche con inventory: cuando la disposición
 * es `restock`, el payload trae TODO lo que `ReceiveStock` necesita (warehouse_id, fulfillment_mode,
 * owner_party_id y las líneas sku + quantity) para re-ingresar el stock al lote del dueño correcto. El
 * orquestador lo cablea por el outbox con un adaptador análogo a `GoodsReceivedStockDispatcher` (inbound →
 * inventory): returns se mantiene EXTRAÍBLE, no depende de inventory.
 *
 * `restock` es un flag explícito para que el consumidor filtre sin re-parsear el valor de `disposition`.
 */
final readonly class ReturnDispositioned extends BaseDomainEvent
{
    /**
     * @param list<array{sku: string, quantity: int}> $lines líneas a re-ingresar (vacío si no es restock)
     */
    public function __construct(
        string $aggregateId,
        public string $disposition,
        public string $kind,
        public string $warehouseId,
        public string $fulfillmentMode,
        public ?string $ownerPartyId,
        public bool $restock,
        public array $lines,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'returns.return_dispositioned';
    }

    public function payload(): array
    {
        return [
            'return_id' => $this->aggregateId,
            'disposition' => $this->disposition,
            'kind' => $this->kind,
            'warehouse_id' => $this->warehouseId,
            'fulfillment_mode' => $this->fulfillmentMode,
            'owner_party_id' => $this->ownerPartyId,
            'restock' => $this->restock,
            'lines' => $this->lines,
        ];
    }
}
