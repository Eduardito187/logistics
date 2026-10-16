<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\StopEnrichmentReader;

/**
 * Enriquecedor de paradas por lectura directa de las tablas de shipments.
 *
 * Resuelve EN LOTE (whereIn) para evitar N+1: una consulta a `shipments` (teléfono + referencia de
 * dirección) y otra a `shipment_lines` (líneas con miniatura). No usa los modelos Eloquent de shipments:
 * lee las columnas con el query builder, de modo que Ejecución no se acopla al dominio del otro contexto.
 * Todo es tolerante a datos ausentes (null); nunca inventa valores.
 */
final readonly class DbStopEnrichmentReader implements StopEnrichmentReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forShipmentIds(array $shipmentIds): array
    {
        $ids = array_values(array_unique(array_filter($shipmentIds, static fn (string $id): bool => trim($id) !== '')));
        if ($ids === []) {
            return [];
        }

        $headers = $this->connection->table('shipments')
            ->whereIn('id', $ids)
            ->get(['id', 'customer_phone', 'address_reference']);

        $lines = $this->connection->table('shipment_lines')
            ->whereIn('shipment_id', $ids)
            ->orderBy('created_at')
            ->get(['shipment_id', 'sku', 'product_name', 'quantity', 'product_image_url']);

        /** @var array<string, list<array{sku: string, name: string, quantity: int, image_url: string|null}>> $itemsByShipment */
        $itemsByShipment = [];
        foreach ($lines as $line) {
            $shipmentId = (string) $line->shipment_id;
            $itemsByShipment[$shipmentId][] = [
                'sku' => (string) $line->sku,
                'name' => (string) $line->product_name,
                'quantity' => (int) $line->quantity,
                'image_url' => $line->product_image_url !== null ? (string) $line->product_image_url : null,
            ];
        }

        $result = [];
        foreach ($headers as $header) {
            $shipmentId = (string) $header->id;
            $result[$shipmentId] = [
                'customer_phone' => $header->customer_phone !== null ? (string) $header->customer_phone : null,
                'address_reference' => $header->address_reference !== null ? (string) $header->address_reference : null,
                'items' => $itemsByShipment[$shipmentId] ?? [],
            ];
        }

        return $result;
    }
}
