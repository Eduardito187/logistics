<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\Contract;

/**
 * Puerto de LECTURA del inventario (lado query de CQRS). Devuelve datos ya agregados en primitivos, para
 * no cargar millones de lotes en memoria: la agregación por almacén/sku/dueño se resuelve en SQL,
 * apoyada en los índices `(warehouse_id, sku, owner_party_id)` y `(warehouse_id, sku, fulfillment_mode)`.
 *
 * La implementación vive en Infrastructure. Devuelve arrays primitivos; los ReadModels de Application los
 * envuelven. No reconstituye agregados (eso es del lado de escritura).
 */
interface StockQueryStore
{
    /**
     * Stock on-hand agregado por DUEÑO para un almacén/sku, con filtros opcionales por dueño/modo.
     *
     * @return list<array{fulfillment_mode: string, owner_party_id: ?string, available: int, reserved: int, on_hand: int, lots: int}>
     */
    public function onHandByOwner(
        string $warehouseId,
        string $sku,
        ?string $ownerPartyId = null,
        ?string $fulfillmentMode = null,
    ): array;

    /**
     * Listado paginado de lotes con filtros opcionales. Orden por id (UUID v7 = orden temporal).
     *
     * @return list<array{id: string, warehouse_id: string, sku: string, fulfillment_mode: string, owner_party_id: ?string, seller_sku_ref: ?string, available_quantity: int, reserved_quantity: int, on_hand: int, unit_cost_cents: int, currency: string, received_at: string, goods_receipt_ref: ?string}>
     */
    public function lots(
        ?string $warehouseId = null,
        ?string $sku = null,
        ?string $ownerPartyId = null,
        ?string $fulfillmentMode = null,
        int $limit = 100,
        int $offset = 0,
    ): array;

    /**
     * Cuenta los lotes que cumplen los MISMOS filtros que `lots()`, para el total de la paginación
     * (COUNT(*) aparte, sin recorrer la tabla).
     */
    public function countLots(
        ?string $warehouseId = null,
        ?string $sku = null,
        ?string $ownerPartyId = null,
        ?string $fulfillmentMode = null,
    ): int;

    /**
     * Available-to-Promise: stock disponible (no reservado) para un almacén/sku, opcionalmente por dueño.
     */
    public function availableToPromise(
        string $warehouseId,
        string $sku,
        ?string $ownerPartyId = null,
    ): int;
}
