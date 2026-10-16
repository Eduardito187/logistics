<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró la recepción física del ASN con su control de calidad, por línea.
 *
 * Lleva la dimensión de propiedad FBD (seller + fulfillment_mode) y el detalle por SKU con aceptado /
 * rechazado / dañado, para que el **orquestador** (subscriber) pueda proyectar el stock con el dueño
 * correcto (contexto inventory, fase 3) sin que inbound dependa de él.
 */
final readonly class GoodsReceived extends BaseDomainEvent
{
    /**
     * @param list<array{sku: string, accepted: int, rejected: int, damaged: int, qc_outcome: string}> $lines
     */
    public function __construct(
        string $aggregateId,
        public string $asnId,
        public string $sellerId,
        public string $warehouseId,
        public string $fulfillmentMode,
        public array $lines,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'inbound.goods_received';
    }

    public function payload(): array
    {
        return [
            'asn_id' => $this->asnId,
            'seller_id' => $this->sellerId,
            'warehouse_id' => $this->warehouseId,
            'fulfillment_mode' => $this->fulfillmentMode,
            'owner_party_id' => $this->sellerId,
            'lines' => $this->lines,
        ];
    }
}
