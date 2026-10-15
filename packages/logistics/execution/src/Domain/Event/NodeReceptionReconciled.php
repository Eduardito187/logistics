<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se cerró la recepción en el nodo conciliando lo recibido contra lo esperado. Es el hecho inmutable que
 * dispara la reacción aguas abajo por el outbox:
 *
 * - Tracking: marca el envío como recibido en el nodo (completo o con discrepancia) y lo hace visible.
 * - SAP (vía conector): encola el traspaso de stock del corredor; las discrepancias (faltantes/sobrantes)
 *   quedan registradas para la conciliación operativa.
 *
 * Lleva el conteo esperado/recibido y las listas de bultos faltantes (esperados que no llegaron) y
 * sobrantes (recibidos que no se esperaban). `complete` es verdadero solo si no hay ni faltantes ni
 * sobrantes. Nodos referenciados por id string: no se acopla al contexto network.
 */
final readonly class NodeReceptionReconciled extends BaseDomainEvent
{
    /**
     * @param list<string> $missingUnitIds Bultos esperados que no se recibieron.
     * @param list<string> $surplusUnitIds Bultos recibidos que no se esperaban.
     */
    public function __construct(
        string $nodeReceptionId,
        public string $shipmentId,
        public string $originNodeId,
        public string $destinationNodeId,
        public string $corridor,
        public int $expectedCount,
        public int $receivedCount,
        public array $missingUnitIds,
        public array $surplusUnitIds,
        public bool $complete,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($nodeReceptionId, null, $occurredAt);
    }

    public function eventName(): string
    {
        return 'execution.node_reception_reconciled';
    }

    public function payload(): array
    {
        return [
            'node_reception_id' => $this->aggregateId,
            'shipment_id' => $this->shipmentId,
            'origin_node_id' => $this->originNodeId,
            'destination_node_id' => $this->destinationNodeId,
            'corridor' => $this->corridor,
            'expected_count' => $this->expectedCount,
            'received_count' => $this->receivedCount,
            'missing_unit_ids' => $this->missingUnitIds,
            'surplus_unit_ids' => $this->surplusUnitIds,
            'complete' => $this->complete,
        ];
    }
}
