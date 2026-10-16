<?php

declare(strict_types=1);

namespace Logistics\Inventory\Infrastructure\Dispatch;

use Logistics\Inventory\Application\Subscriber\ReceiveStockOnGoodsReceived;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Adaptador de outbox que engancha la logística inversa (returns, Fase 4) con el inventario (Fase 3):
 *
 *   returns.return_dispositioned (disposition = restock)  ──►  ReceiveStock (reingreso al lote del dueño)
 *
 * Cuando una devolución se dispone como "restock", su producto vuelve a stock con su dueño correcto
 * (retail o consignación del seller). Solo actúa si `restock === true`; las disposiciones
 * `return_to_seller`/`scrap` NO reingresan stock. Reutiliza el subscriber {@see ReceiveStockOnGoodsReceived}
 * (que despacha `ReceiveStock`) mapeando cada línea `{sku, quantity}` a su forma plana. Se mantiene
 * EXTRAÍBLE: NO depende del package `returns`; consume el evento por nombre y payload.
 */
final readonly class ReturnRestockDispatcher implements MessageDispatcher
{
    private const EVENT = 'returns.return_dispositioned';

    public function __construct(
        private ReceiveStockOnGoodsReceived $subscriber,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== self::EVENT) {
            return;
        }

        $payload = $message->payload();

        if (($payload['restock'] ?? false) !== true) {
            return;
        }

        $lines = $payload['lines'] ?? [];
        if (!is_array($lines)) {
            return;
        }

        $warehouseId = $this->string($payload, 'warehouse_id');
        $fulfillmentMode = $this->string($payload, 'fulfillment_mode') ?? 'owned';
        $ownerPartyId = $this->string($payload, 'owner_party_id');
        $returnRef = $this->string($payload, 'return_id');

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $quantity = $this->int($line, 'quantity');
            $sku = $this->string($line, 'sku');
            if ($quantity === null || $quantity <= 0 || $sku === null) {
                continue;
            }

            $this->subscriber->handle([
                'warehouse_id' => $warehouseId,
                'sku' => $sku,
                'accepted_quantity' => $quantity,
                'fulfillment_mode' => $fulfillmentMode,
                'owner_party_id' => $ownerPartyId,
                'goods_receipt_ref' => $returnRef,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
