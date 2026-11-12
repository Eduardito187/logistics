<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

/**
 * Resumen de una recepción en nodo para el listado de la tienda: el envío (con su referencia legible de
 * pedido y cliente) y el estado de la recepción con sus conteos, en primitivos. Si se cachea, se cachea su
 * toArray() (regla 7).
 */
final readonly class NodeReceptionSummaryView
{
    public function __construct(
        public string $id,
        public string $shipmentId,
        public ?string $orderReference,
        public ?string $customerName,
        public string $originNodeId,
        public string $destinationNodeId,
        public string $corridor,
        public string $status,
        public int $expectedCount,
        public int $receivedCount,
        public int $missingCount,
        public int $surplusCount,
        public string $openedAt,
        public ?string $reconciledAt,
    ) {}

    /**
     * Arma el resumen a partir de los ids esperados y recibidos: los conteos de faltantes y sobrantes se
     * calculan igual que en el agregado (diferencia de conjuntos), para que nunca se contradigan.
     *
     * @param list<string> $expectedUnitIds
     * @param list<string> $receivedUnitIds
     */
    public static function fromIds(
        string $id,
        string $shipmentId,
        ?string $orderReference,
        ?string $customerName,
        string $originNodeId,
        string $destinationNodeId,
        string $corridor,
        string $status,
        array $expectedUnitIds,
        array $receivedUnitIds,
        string $openedAt,
        ?string $reconciledAt,
    ): self {
        $expected = array_values(array_unique($expectedUnitIds));
        $received = array_values(array_unique($receivedUnitIds));

        return new self(
            $id,
            $shipmentId,
            $orderReference,
            $customerName,
            $originNodeId,
            $destinationNodeId,
            $corridor,
            $status,
            count($expected),
            count($received),
            count(array_diff($expected, $received)),
            count(array_diff($received, $expected)),
            $openedAt,
            $reconciledAt,
        );
    }

    /**
     * Forma del ítem del listado: el envío arriba y la recepción anidada en `reception` (lo que pide la
     * app para pintar el estado sin una llamada por fila).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'order_reference' => $this->orderReference,
            'customer_name' => $this->customerName,
            'origin_node_id' => $this->originNodeId,
            'destination_node_id' => $this->destinationNodeId,
            'corridor' => $this->corridor,
            'reception' => [
                'status' => $this->status,
                'expected_count' => $this->expectedCount,
                'received_count' => $this->receivedCount,
                'missing_count' => $this->missingCount,
                'surplus_count' => $this->surplusCount,
                'opened_at' => $this->openedAt,
                'reconciled_at' => $this->reconciledAt,
            ],
        ];
    }
}
