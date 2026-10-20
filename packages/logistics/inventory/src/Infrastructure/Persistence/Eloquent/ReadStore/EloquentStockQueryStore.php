<?php

declare(strict_types=1);

namespace Logistics\Inventory\Infrastructure\Persistence\Eloquent\ReadStore;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Logistics\Inventory\Domain\Contract\StockQueryStore;

/**
 * Implementación del lado de LECTURA del inventario. Agrega en SQL (sin cargar lotes en memoria) apoyada
 * en los índices `(warehouse_id, sku, owner_party_id)` y `(warehouse_id, sku, fulfillment_mode)`, para
 * escalar a millones de lotes. Devuelve primitivos; los ReadModels de Application los envuelven.
 */
final class EloquentStockQueryStore implements StockQueryStore
{
    public function onHandByOwner(
        string $warehouseId,
        string $sku,
        ?string $ownerPartyId = null,
        ?string $fulfillmentMode = null,
    ): array {
        $query = DB::table('inventory_lots')
            ->where('warehouse_id', $warehouseId)
            ->where('sku', $sku);

        if ($ownerPartyId !== null) {
            $query->where('owner_party_id', $ownerPartyId);
        }
        if ($fulfillmentMode !== null) {
            $query->where('fulfillment_mode', $fulfillmentMode);
        }

        $rows = $query
            ->groupBy('fulfillment_mode', 'owner_party_id')
            ->orderBy('fulfillment_mode')
            ->get([
                'fulfillment_mode',
                'owner_party_id',
                DB::raw('SUM(available_quantity) AS available'),
                DB::raw('SUM(reserved_quantity) AS reserved'),
                DB::raw('SUM(available_quantity + reserved_quantity) AS on_hand'),
                DB::raw('COUNT(*) AS lots'),
            ]);

        $result = [];
        foreach ($rows as $row) {
            $ownerValue = $row->owner_party_id;
            $result[] = [
                'fulfillment_mode' => (string) $row->fulfillment_mode,
                'owner_party_id' => $ownerValue !== null ? (string) $ownerValue : null,
                'available' => (int) $row->available,
                'reserved' => (int) $row->reserved,
                'on_hand' => (int) $row->on_hand,
                'lots' => (int) $row->lots,
            ];
        }

        return $result;
    }

    public function lots(
        ?string $warehouseId = null,
        ?string $sku = null,
        ?string $ownerPartyId = null,
        ?string $fulfillmentMode = null,
        int $limit = 100,
        int $offset = 0,
    ): array {
        // UUID v7 = orden temporal de ingreso; paginado por limit/offset.
        $rows = $this->filteredLots($warehouseId, $sku, $ownerPartyId, $fulfillmentMode)
            ->orderBy('id')->limit(max(1, $limit))->offset(max(0, $offset))->get();

        $result = [];
        foreach ($rows as $row) {
            $available = (int) $row->available_quantity;
            $reserved = (int) $row->reserved_quantity;
            $ownerValue = $row->owner_party_id;
            $sellerSkuRef = $row->seller_sku_ref;
            $goodsReceiptRef = $row->goods_receipt_ref;
            $result[] = [
                'id' => (string) $row->id,
                'warehouse_id' => (string) $row->warehouse_id,
                'sku' => (string) $row->sku,
                'fulfillment_mode' => (string) $row->fulfillment_mode,
                'owner_party_id' => $ownerValue !== null ? (string) $ownerValue : null,
                'seller_sku_ref' => $sellerSkuRef !== null ? (string) $sellerSkuRef : null,
                'available_quantity' => $available,
                'reserved_quantity' => $reserved,
                'on_hand' => $available + $reserved,
                'unit_cost_cents' => (int) $row->unit_cost_cents,
                'currency' => (string) $row->currency,
                'received_at' => (string) $row->received_at,
                'goods_receipt_ref' => $goodsReceiptRef !== null ? (string) $goodsReceiptRef : null,
            ];
        }

        return $result;
    }

    public function countLots(
        ?string $warehouseId = null,
        ?string $sku = null,
        ?string $ownerPartyId = null,
        ?string $fulfillmentMode = null,
    ): int {
        return $this->filteredLots($warehouseId, $sku, $ownerPartyId, $fulfillmentMode)->count();
    }

    /**
     * Query builder con los filtros comunes de lots/countLots (sin orden ni recorte).
     */
    private function filteredLots(
        ?string $warehouseId,
        ?string $sku,
        ?string $ownerPartyId,
        ?string $fulfillmentMode,
    ): Builder {
        $query = DB::table('inventory_lots');

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($sku !== null) {
            $query->where('sku', $sku);
        }
        if ($ownerPartyId !== null) {
            $query->where('owner_party_id', $ownerPartyId);
        }
        if ($fulfillmentMode !== null) {
            $query->where('fulfillment_mode', $fulfillmentMode);
        }

        return $query;
    }

    public function availableToPromise(
        string $warehouseId,
        string $sku,
        ?string $ownerPartyId = null,
    ): int {
        $query = DB::table('inventory_lots')
            ->where('warehouse_id', $warehouseId)
            ->where('sku', $sku);

        if ($ownerPartyId !== null) {
            $query->where('owner_party_id', $ownerPartyId);
        }

        /** @var Builder $query */
        return (int) $query->sum('available_quantity');
    }
}
