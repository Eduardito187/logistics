<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Support\Collection;
use Logistics\Shipments\Application\Contract\ShipmentListReader;
use Logistics\Shipments\Application\ReadModel\ShipmentLineView;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\ValueObject\AddressConfidenceLevel;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Implementación SQL del lado de lectura del listado de envíos (CQRS).
 *
 * Empuja TODOS los filtros a la base (WHERE indexables) y arma los ShipmentView directamente desde las
 * filas, sin reconstruir el agregado ni tocar la tabla de bultos. Dos consultas acotadas a la página
 * pedida —cabeceras + sus líneas por whereIn— en vez de materializar la tabla completa.
 *
 * ShipmentRecord/ShipmentLineRecord son el mapeo de filas de Infrastructure (no el agregado de Domain):
 * usarlos aquí no viola la separación de capas y mantiene el tipado para el análisis estático.
 */
final class DbShipmentListReader implements ShipmentListReader
{
    public function list(
        ?string $status = null,
        ?string $source = null,
        ?string $destinationNodeId = null,
        bool $lowConfidenceOnly = false,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        $query = ShipmentRecord::query();

        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($source !== null) {
            $query->where('source', $source);
        }
        if ($destinationNodeId !== null) {
            $query->where('destination_node_id', $destinationNodeId);
        }
        if ($lowConfidenceOnly) {
            $query->where('address_confidence_level', AddressConfidenceLevel::Low->value);
        }

        // Orden estable por id (UUID v7 = orden temporal), igual que el listado histórico.
        $query->orderBy('id');

        if ($offset !== null) {
            $query->offset($offset);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var Collection<int, ShipmentRecord> $records */
        $records = $query->get();

        if ($records->isEmpty()) {
            return [];
        }

        $shipmentIds = $records->map(static fn (ShipmentRecord $record): string => $record->id)->all();
        $linesByShipment = $this->linesFor($shipmentIds);

        $views = [];
        foreach ($records as $record) {
            $views[] = $this->toView($record, $linesByShipment[$record->id] ?? []);
        }

        return $views;
    }

    public function count(
        ?string $status = null,
        ?string $source = null,
        ?string $destinationNodeId = null,
        bool $lowConfidenceOnly = false,
    ): int {
        $query = ShipmentRecord::query();

        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($source !== null) {
            $query->where('source', $source);
        }
        if ($destinationNodeId !== null) {
            $query->where('destination_node_id', $destinationNodeId);
        }
        if ($lowConfidenceOnly) {
            $query->where('address_confidence_level', AddressConfidenceLevel::Low->value);
        }

        // COUNT(*) acotado al filtro (no toca la tabla de líneas ni reconstruye agregados).
        return $query->count();
    }

    /**
     * Carga en UNA sola consulta las líneas de todos los envíos de la página y las agrupa por envío,
     * para evitar el N+1. Las líneas van ordenadas por id (orden de inserción, UUID v7).
     *
     * @param  array<int, string>                    $shipmentIds
     * @return array<string, list<ShipmentLineView>>
     */
    private function linesFor(array $shipmentIds): array
    {
        /** @var Collection<int, ShipmentLineRecord> $lineRecords */
        $lineRecords = ShipmentLineRecord::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($lineRecords as $line) {
            $hasPrice = $line->unit_price !== null;

            $grouped[$line->shipment_id][] = new ShipmentLineView(
                $line->id,
                $line->sku,
                $line->product_name,
                $line->quantity,
                $line->product_image_url,
                $hasPrice ? (float) $line->unit_price : null,
                $hasPrice ? ($line->unit_price_currency ?? 'BOB') : null,
            );
        }

        return $grouped;
    }

    /**
     * @param list<ShipmentLineView> $lines
     */
    private function toView(ShipmentRecord $record, array $lines): ShipmentView
    {
        return ShipmentView::fromPrimitives(
            $record->id,
            $record->source,
            $record->external_id,
            $record->customer_name,
            $record->customer_document_id,
            $record->customer_phone,
            $record->customer_email,
            $record->address_text,
            $record->address_reference,
            $record->lat !== null ? (float) $record->lat : null,
            $record->lng !== null ? (float) $record->lng : null,
            $record->address_confidence,
            $record->address_confidence_level,
            $record->status,
            $record->delivery_date,
            $record->delivery_window_start,
            $record->delivery_window_end,
            $record->destination_node_id,
            $lines,
        );
    }
}
