<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Logistics\Execution\Application\Contract\StopEnrichmentReader;

/**
 * Enriquecedor de paradas por lectura directa de las tablas de shipments.
 *
 * Resuelve EN LOTE (whereIn) para evitar N+1: una consulta a `shipments` (teléfono + referencia de
 * dirección) y otra a `shipment_lines` (líneas con miniatura). No usa los modelos Eloquent de shipments:
 * lee las columnas con el query builder, de modo que Ejecución no se acopla al dominio del otro contexto.
 * El teléfono y la referencia de dirección son tolerantes a datos ausentes (null). El producto de cada
 * línea, en cambio, SIEMPRE sale legible (nombre + SKU, con fallback a SKU y a "Producto"): nunca null
 * ni vacío, porque es lo que el chofer lee en la pantalla de entrega.
 */
final readonly class DbStopEnrichmentReader implements StopEnrichmentReader
{
    /** network_nodes.type → tipo de destino expuesto (store|warehouse); igual que en dispatch-lines. */
    private const array NODE_TYPE_MAP = [
        'distribution_center' => 'warehouse',
        'central_warehouse' => 'warehouse',
        'store' => 'store',
        'pickup_point' => 'store',
    ];

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
            ->get(['id', 'customer_phone', 'address_reference', 'customer_name', 'destination_node_id']);

        $lines = $this->connection->table('shipment_lines')
            ->whereIn('shipment_id', $ids)
            ->orderBy('created_at')
            ->get(['shipment_id', 'sku', 'product_name', 'quantity', 'product_image_url']);

        $itemsByShipment = $this->groupItemsByShipment($lines);
        $nodesById = $this->nodesByIdFor($headers);

        $result = [];
        foreach ($headers as $header) {
            $shipmentId = (string) $header->id;
            $result[$shipmentId] = [
                'customer_phone' => $header->customer_phone !== null ? (string) $header->customer_phone : null,
                'address_reference' => $header->address_reference !== null ? (string) $header->address_reference : null,
                'items' => $itemsByShipment[$shipmentId] ?? [],
                'destination' => $this->resolveDestination($header, $nodesById),
            ];
        }

        return $result;
    }

    /**
     * Carga EN LOTE los nodos destino referenciados por las cabeceras (evita N+1).
     *
     * @param  Collection<int, \stdClass>                                               $headers
     * @return array<string, array{name: string|null, type: string, code: string|null}>
     */
    private function nodesByIdFor(Collection $headers): array
    {
        $nodeIds = [];
        foreach ($headers as $header) {
            if ($header->destination_node_id !== null && trim((string) $header->destination_node_id) !== '') {
                $nodeIds[] = (string) $header->destination_node_id;
            }
        }
        $nodeIds = array_values(array_unique($nodeIds));
        if ($nodeIds === []) {
            return [];
        }

        $nodes = [];
        foreach ($this->connection->table('network_nodes')->whereIn('id', $nodeIds)->get(['id', 'name', 'type', 'code']) as $node) {
            $nodes[(string) $node->id] = [
                'name' => $node->name !== null ? (string) $node->name : null,
                'type' => self::NODE_TYPE_MAP[(string) $node->type] ?? 'store',
                'code' => $node->code !== null ? (string) $node->code : null,
            ];
        }

        return $nodes;
    }

    /**
     * Tipo de destino de la parada: domicilio (sin nodo) o nodo (store|warehouse) con su nombre y código.
     * Es lo que la app usa para elegir la acción: "Entregar" (POD) vs "Descargar" (escaneo de descarga).
     *
     * @param  array<string, array{name: string|null, type: string, code: string|null}> $nodesById
     * @return array{type: string, name: string|null, node_code: string|null}
     */
    private function resolveDestination(\stdClass $header, array $nodesById): array
    {
        $nodeId = $header->destination_node_id !== null ? trim((string) $header->destination_node_id) : '';
        if ($nodeId !== '' && isset($nodesById[$nodeId])) {
            $node = $nodesById[$nodeId];

            return ['type' => $node['type'], 'name' => $node['name'], 'node_code' => $node['code']];
        }

        // Sin nodo destino: entrega a domicilio. El nombre, si lo hay, es el del cliente.
        return [
            'type' => 'home',
            'name' => $header->customer_name !== null && trim((string) $header->customer_name) !== '' ? (string) $header->customer_name : null,
            'node_code' => null,
        ];
    }

    /**
     * Agrupa las líneas por envío, con el producto SIEMPRE legible (ver mapLine), listo para pantalla.
     *
     * @param  Collection<int, \stdClass>                                                                   $lines
     * @return array<string, list<array{sku: string, name: string, quantity: int, image_url: string|null}>>
     */
    private function groupItemsByShipment(Collection $lines): array
    {
        $itemsByShipment = [];
        foreach ($lines as $line) {
            $shipmentId = (string) $line->shipment_id;
            $itemsByShipment[$shipmentId][] = $this->mapLine($line);
        }

        return $itemsByShipment;
    }

    /**
     * Mapea una línea a su forma de pantalla. Producto siempre legible: nombre + SKU. El SKU nunca es
     * null (cae a ""); si falta el nombre se usa el SKU y, si faltan ambos, "Producto". Nunca null/vacío.
     *
     * @return array{sku: string, name: string, quantity: int, image_url: string|null}
     */
    private function mapLine(\stdClass $line): array
    {
        $sku = $line->sku !== null ? trim((string) $line->sku) : '';
        $name = $line->product_name !== null ? trim((string) $line->product_name) : '';
        if ($name === '') {
            $name = $sku !== '' ? $sku : 'Producto';
        }

        return [
            'sku' => $sku,
            'name' => $name,
            'quantity' => (int) $line->quantity,
            'image_url' => $line->product_image_url !== null ? (string) $line->product_image_url : null,
        ];
    }
}
