<?php

declare(strict_types=1);

namespace Logistics\Inventory\Infrastructure\Dispatch;

use Logistics\Inventory\Application\Subscriber\ReceiveStockOnGoodsReceived;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Adaptador de outbox que engancha la Fase 2 (inbound) con la Fase 3 (inventory):
 *
 *   inbound.goods_received  ──►  ReceiveStock (un lote por línea aceptada, con su dueño)
 *
 * El evento `inbound.goods_received` llega como UN mensaje con sus líneas (`lines[]`); el subscriber
 * {@see ReceiveStockOnGoodsReceived} espera la forma PLANA por línea. Este adaptador hace la traducción:
 * aplana la cabecera (almacén + propiedad) sobre cada línea con cantidad aceptada > 0 y delega. Se mantiene
 * EXTRAÍBLE: depende del contrato de outbox y de su propio subscriber, nunca del package `inbound`.
 *
 * Idempotencia: el outbox reentrega at-least-once; `goods_receipt_ref` (el asn de origen) viaja a cada
 * lote para poder deduplicar/trazar el ingreso.
 */
final readonly class GoodsReceivedStockDispatcher implements MessageDispatcher
{
    public function __construct(
        private ReceiveStockOnGoodsReceived $subscriber,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== $this->subscriber->subscribedTo()) {
            return;
        }

        $payload = $message->payload();

        $lines = $payload['lines'] ?? [];
        if (!is_array($lines)) {
            return;
        }

        $warehouseId = $this->string($payload, 'warehouse_id');
        $fulfillmentMode = $this->string($payload, 'fulfillment_mode') ?? 'owned';
        $ownerPartyId = $this->string($payload, 'owner_party_id');
        $sellerSkuRef = $this->string($payload, 'seller_sku_ref');
        $goodsReceiptRef = $this->string($payload, 'asn_id');

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $accepted = $this->int($line, 'accepted');
            $sku = $this->string($line, 'sku');
            if ($accepted === null || $accepted <= 0 || $sku === null) {
                continue;
            }

            $this->subscriber->handle([
                'warehouse_id' => $warehouseId,
                'sku' => $sku,
                'accepted_quantity' => $accepted,
                'fulfillment_mode' => $fulfillmentMode,
                'owner_party_id' => $ownerPartyId,
                'seller_sku_ref' => $sellerSkuRef,
                'goods_receipt_ref' => $goodsReceiptRef,
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
