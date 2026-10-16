<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Subscriber;

use Logistics\Inventory\Application\Command\ReceiveStock\ReceiveStock;
use Logistics\Inventory\Domain\Exception\InventoryException;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Enganche cross-context de la Fase 2 (inbound) con la Fase 3 (inventory):
 *
 *   inbound.GoodsReceived  ──►  ReceiveStock  (crea el lote con el dueño correcto)
 *
 * Es el punto donde el producto recibido y etiquetado se vuelve stock con atribución de dueño. Se mantiene
 * EXTRAÍBLE a microservicio a propósito: NO hay dependencia composer a `inbound`. Este suscriptor consume
 * el evento por su NOMBRE (`inbound.goods_received`) y su PAYLOAD primitivo, nada más; el orquestador lo
 * registra en el relay/dispatcher del outbox.
 *
 * Nombre de evento esperado: `inbound.goods_received`.
 *
 * Payload esperado (array<string, mixed>), tal como lo emite el GoodsReceipt de inbound por línea aceptada:
 *   - warehouse_id       string   almacén central donde se recibió (node id)         [requerido]
 *   - sku                string   SKU interno de la unidad etiquetada                [requerido]
 *   - accepted_quantity  int      cantidad realmente aceptada (QC OK) de la línea    [requerido]
 *   - fulfillment_mode   string   'owned' | 'fbd' | 'dropship'                       [opc; default 'owned']
 *   - owner_party_id     ?string  id del seller dueño (null si es propio de Dismac)  [opc]
 *   - seller_sku_ref     ?string  SKU del seller (referencia)                        [opc]
 *   - unit_cost_cents    int      costo/valor unitario en centavos                   [opc; default 0]
 *   - currency           string   moneda del costo                                   [opc; default 'BOB']
 *   - goods_receipt_ref  ?string  referencia al goods receipt de origen (trazabilidad)[opc]
 *   - received_at        ?string  fecha de ingreso ISO-8601 (null = ahora)           [opc]
 */
final readonly class ReceiveStockOnGoodsReceived implements DomainEventSubscriber
{
    public function __construct(
        private CommandBus $commands,
    ) {}

    public function subscribedTo(): string
    {
        return 'inbound.goods_received';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        $this->commands->dispatch(new ReceiveStock(
            warehouseId: $this->requireString($payload, 'warehouse_id'),
            sku: $this->requireString($payload, 'sku'),
            quantity: $this->requireInt($payload, 'accepted_quantity'),
            fulfillmentMode: $this->optionalString($payload, 'fulfillment_mode') ?? 'owned',
            ownerPartyId: $this->optionalString($payload, 'owner_party_id'),
            sellerSkuRef: $this->optionalString($payload, 'seller_sku_ref'),
            unitCostCents: $this->optionalInt($payload, 'unit_cost_cents') ?? 0,
            currency: $this->optionalString($payload, 'currency') ?? 'BOB',
            receivedAt: $this->optionalString($payload, 'received_at'),
            goodsReceiptRef: $this->optionalString($payload, 'goods_receipt_ref'),
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireString(array $payload, string $key): string
    {
        $value = $this->optionalString($payload, $key);
        if ($value === null || $value === '') {
            throw InventoryException::because("El payload de inbound.goods_received no trae '{$key}'.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireInt(array $payload, string $key): int
    {
        $value = $this->optionalInt($payload, $key);
        if ($value === null) {
            throw InventoryException::because("El payload de inbound.goods_received no trae '{$key}'.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }
        $value = $payload[$key];

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalInt(array $payload, string $key): ?int
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }
        $value = $payload[$key];

        return is_numeric($value) ? (int) $value : null;
    }
}
