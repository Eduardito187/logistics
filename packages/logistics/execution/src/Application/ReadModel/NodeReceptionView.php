<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use Logistics\Execution\Domain\Model\NodeReception;

/**
 * Modelo de lectura de la recepción en nodo: forma plana en primitivos para APIs, listados y la app.
 *
 * Expone el resultado de la conciliación (esperados, recibidos, faltantes, sobrantes) ya calculado; las
 * consultas nunca exponen el objeto de dominio. Aditivo: cada producto trae su ubicación en estante vigente
 * (`location`/`located_at`) y, una vez conciliada, el informe del operador (`reconciliation`: notas, foto,
 * motivo de cada faltante y envío real de cada sobrante).
 */
final readonly class NodeReceptionView
{
    /**
     * `units`: producto de cada unidad física (esperadas ∪ recibidas ∪ sobrantes); nombre y SKU siempre
     * legibles, foto/índices null si no se resolvió. `reconciliation`: informe del operador; null mientras la
     * recepción está abierta.
     *
     * @param list<string>                                                                                                                                                                                                                              $expectedUnitIds
     * @param list<string>                                                                                                                                                                                                                              $receivedUnitIds
     * @param list<string>                                                                                                                                                                                                                              $missingUnitIds
     * @param list<string>                                                                                                                                                                                                                              $surplusUnitIds
     * @param list<array{unit_id: string, sku: string, product_name: string, product_image_url: string|null, unit_index: int|null, unit_total: int|null, piece_index: int|null, piece_total: int|null, location: string|null, located_at: string|null}> $units
     * @param array{notes: string|null, photo_path: string|null, missing: list<array{unit_id: string, reason: string|null}>, surplus: list<array{unit_id: string, belongs_to_shipment_id: string|null}>}|null                                           $reconciliation
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
        public array $units = [],
        public ?array $reconciliation = null,
    ) {}

    /**
     * @param array<string, array{sku: string|null, product_name: string|null, product_image_url: string|null, unit_index: int|null, unit_total: int|null, piece_index: int|null, piece_total: int|null}> $products
     *                                                                                                                                                                                                              mapa unit id => producto (de ShipmentUnitProductReader); los ids ausentes salen con nulls
     */
    public static function fromNodeReception(NodeReception $reception, array $products = []): self
    {
        $missing = $reception->missingUnitIds();
        $surplus = $reception->surplusUnitIds();
        $received = $reception->receivedUnitIds();

        $units = [];
        foreach (array_values(array_unique([...$reception->expectedUnitIds(), ...$received, ...$surplus])) as $unitId) {
            $product = $products[$unitId] ?? null;
            // Nombre y SKU SIEMPRE legibles (regla del dueño): el SKU nunca es null (cae a ""), y si
            // falta el nombre se usa el SKU y, si faltan ambos, "Producto". La foto y los índices sí
            // pueden faltar (null) para un id que no resuelve a una unidad conocida.
            $sku = isset($product['sku']) && is_string($product['sku']) ? trim($product['sku']) : '';
            $name = isset($product['product_name']) && is_string($product['product_name']) ? trim($product['product_name']) : '';
            if ($name === '') {
                $name = $sku !== '' ? $sku : 'Producto';
            }
            $shelf = $reception->shelfLocationOf($unitId);
            $units[] = [
                'unit_id' => $unitId,
                'sku' => $sku,
                'product_name' => $name,
                'product_image_url' => $product['product_image_url'] ?? null,
                'unit_index' => $product['unit_index'] ?? null,
                'unit_total' => $product['unit_total'] ?? null,
                'piece_index' => $product['piece_index'] ?? null,
                'piece_total' => $product['piece_total'] ?? null,
                // Ubicación en estante vigente dentro del nodo (null si todavía no se ubicó).
                'location' => $shelf?->location,
                'located_at' => $shelf?->locatedAt->format(DATE_ATOM),
            ];
        }

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
            $units,
            self::reconciliationOf($reception, $missing, $surplus),
        );
    }

    /**
     * Informe de la conciliación: CADA faltante y sobrante calculado, con el motivo / envío real que haya
     * declarado el operador (null si no declaró). Null mientras la recepción sigue abierta.
     *
     * @param  list<string>                                                                                                                                                                                    $missing
     * @param  list<string>                                                                                                                                                                                    $surplus
     * @return array{notes: string|null, photo_path: string|null, missing: list<array{unit_id: string, reason: string|null}>, surplus: list<array{unit_id: string, belongs_to_shipment_id: string|null}>}|null
     */
    private static function reconciliationOf(NodeReception $reception, array $missing, array $surplus): ?array
    {
        if ($reception->reconciledAt() === null) {
            return null;
        }

        $report = $reception->reconciliationReport();

        return [
            'notes' => $report->notes,
            'photo_path' => $report->photoPath,
            'missing' => array_map(
                static fn (string $unitId): array => ['unit_id' => $unitId, 'reason' => $report->reasonFor($unitId)?->value],
                $missing,
            ),
            'surplus' => array_map(
                static fn (string $unitId): array => ['unit_id' => $unitId, 'belongs_to_shipment_id' => $report->belongsToShipmentFor($unitId)],
                $surplus,
            ),
        ];
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
            'missing_count' => count($this->missingUnitIds),
            'surplus_count' => count($this->surplusUnitIds),
            'complete' => $this->complete,
            'opened_at' => $this->openedAt,
            'reconciled_at' => $this->reconciledAt,
            'units' => $this->units,
            'reconciliation' => $this->reconciliation,
        ];
    }
}
