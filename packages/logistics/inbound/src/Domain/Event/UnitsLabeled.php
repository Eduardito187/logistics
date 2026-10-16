<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se etiquetaron las unidades aceptadas de un goods receipt: el **punto de convergencia**.
 *
 * Lleva todo lo que el orquestador necesita para crear la unidad física en `shipments` y emitir la
 * etiqueta QR en `labels` (seller, sku, cantidades, ownership FBD). A partir de aquí la unidad es
 * operacionalmente idéntica a una Dismac y la distribución existente se reutiliza sin cambios. inbound NO
 * depende de shipments ni de labels: solo publica el hecho por el outbox.
 */
final readonly class UnitsLabeled extends BaseDomainEvent
{
    /**
     * @param list<array{sku: string, quantity: int}> $units
     */
    public function __construct(
        string $aggregateId,
        public string $asnId,
        public string $sellerId,
        public string $warehouseId,
        public string $fulfillmentMode,
        public array $units,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'inbound.units_labeled';
    }

    public function payload(): array
    {
        return [
            'asn_id' => $this->asnId,
            'seller_id' => $this->sellerId,
            'warehouse_id' => $this->warehouseId,
            'fulfillment_mode' => $this->fulfillmentMode,
            'owner_party_id' => $this->sellerId,
            'units' => $this->units,
        ];
    }
}
