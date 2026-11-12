<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\ReadModel;

/**
 * Proyección de lectura de un viaje asignado a un transportista CON su carga (envíos → productos), para
 * `GET /app/carriers/me/assignments`. La arma el CarrierAssignmentReader en lote.
 *
 * Reglas de presentación (dueño del proyecto): nodos por NOMBRE legible (el id va aparte, aditivo);
 * productos SIEMPRE con nombre + SKU, nunca null/vacío ni la palabra "bulto".
 *
 * @phpstan-type UnitShape array{unit_id: string, sku: string, product_name: string, product_label: string, piece_index: int, piece_total: int}
 * @phpstan-type ShipmentShape array{shipment_id: string, external_id: string, order_reference: string|null, customer_name: string, destination_node: string|null, destination_node_id: string|null, units_count: int, units: list<UnitShape>}
 */
final readonly class CarrierAssignmentView
{
    /**
     * @param list<ShipmentShape> $shipments
     */
    public function __construct(
        public string $tripId,
        public string $carrierId,
        public ?string $carrierName,
        public ?string $reference,
        public string $corridorFrom,
        public string $corridorFromNodeId,
        public string $corridorTo,
        public string $corridorToNodeId,
        public string $scheduledAt,
        public string $status,
        public ?string $startedAt,
        public ?string $closedAt,
        public array $shipments,
    ) {}

    /**
     * Total de productos (unidades físicas) que el transportista debe recoger en el viaje.
     */
    public function expectedUnitsCount(): int
    {
        return array_sum(array_map(static fn (array $shipment): int => $shipment['units_count'], $this->shipments));
    }

    /**
     * @return array{trip_id: string, carrier_id: string, carrier_name: string|null, reference: string|null, corridor_from: string, corridor_from_node_id: string, corridor_to: string, corridor_to_node_id: string, scheduled_at: string, status: string, started_at: string|null, closed_at: string|null, shipments_count: int, expected_units_count: int, shipments: list<ShipmentShape>}
     */
    public function toArray(): array
    {
        return [
            'trip_id' => $this->tripId,
            'carrier_id' => $this->carrierId,
            'carrier_name' => $this->carrierName,
            'reference' => $this->reference,
            'corridor_from' => $this->corridorFrom,
            'corridor_from_node_id' => $this->corridorFromNodeId,
            'corridor_to' => $this->corridorTo,
            'corridor_to_node_id' => $this->corridorToNodeId,
            'scheduled_at' => $this->scheduledAt,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'closed_at' => $this->closedAt,
            'shipments_count' => \count($this->shipments),
            'expected_units_count' => $this->expectedUnitsCount(),
            'shipments' => $this->shipments,
        ];
    }
}
