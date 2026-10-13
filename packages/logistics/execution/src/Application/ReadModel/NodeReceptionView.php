<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use Logistics\Execution\Domain\Model\NodeReception;

/**
 * Modelo de lectura de la recepción en nodo: forma plana en primitivos para APIs, listados y la app.
 *
 * Expone el resultado de la conciliación (esperados, recibidos, faltantes, sobrantes) ya calculado; las
 * consultas nunca exponen el objeto de dominio.
 */
final readonly class NodeReceptionView
{
    /**
     * @param list<string> $expectedUnitIds
     * @param list<string> $receivedUnitIds
     * @param list<string> $missingUnitIds
     * @param list<string> $surplusUnitIds
     */
    public function __construct(
        public string $id,
        public string $shipmentId,
        public string $originNodeId,
        public string $destinationNodeId,
        public string $corridor,
        public string $status,
        public array $expectedUnitIds,
        public array $receivedUnitIds,
        public array $missingUnitIds,
        public array $surplusUnitIds,
        public int $expectedCount,
        public int $receivedCount,
        public bool $complete,
        public string $openedAt,
        public ?string $reconciledAt,
    ) {}

    public static function fromNodeReception(NodeReception $reception): self
    {
        $missing = $reception->missingUnitIds();
        $surplus = $reception->surplusUnitIds();
        $received = $reception->receivedUnitIds();

        return new self(
            $reception->id()->toString(),
            $reception->shipmentId(),
            $reception->originNodeId(),
            $reception->destinationNodeId(),
            $reception->corridor()->value,
            $reception->status()->value,
            $reception->expectedUnitIds(),
            $received,
            $missing,
            $surplus,
            count($reception->expectedUnitIds()),
            count($received),
            $missing === [] && $surplus === [],
            $reception->openedAt()->format(DATE_ATOM),
            $reception->reconciledAt()?->format(DATE_ATOM),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'origin_node_id' => $this->originNodeId,
            'destination_node_id' => $this->destinationNodeId,
            'corridor' => $this->corridor,
            'status' => $this->status,
            'expected_unit_ids' => $this->expectedUnitIds,
            'received_unit_ids' => $this->receivedUnitIds,
            'missing_unit_ids' => $this->missingUnitIds,
            'surplus_unit_ids' => $this->surplusUnitIds,
            'expected_count' => $this->expectedCount,
            'received_count' => $this->receivedCount,
            'complete' => $this->complete,
            'opened_at' => $this->openedAt,
            'reconciled_at' => $this->reconciledAt,
        ];
    }
}
