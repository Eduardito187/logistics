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
     * @param list<string>                                                      $missingUnitIds Bultos esperados que no se recibieron.
     * @param list<string>                                                      $surplusUnitIds Bultos recibidos que no se esperaban.
     * @param list<array{unit_id: string, reason: string}>                      $missingUnits   Motivo declarado de cada faltante (aditivo).
     * @param list<array{unit_id: string, belongs_to_shipment_id: string|null}> $surplusUnits   Envío real declarado de cada sobrante (aditivo).
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
        public array $missingUnits = [],
        public array $surplusUnits = [],
        public ?string $notes = null,
        public ?string $photoPath = null,
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
            // Informe del operador (aditivo): motivo de cada faltante, envío real de cada sobrante, notas y foto.
            'missing_units' => $this->missingUnits,
            'surplus_units' => $this->surplusUnits,
            'notes' => $this->notes,
            'photo_path' => $this->photoPath,
        ];
    }
}
