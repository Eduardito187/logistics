<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

/**
 * Modelo de lectura de una parada del plan: forma plana en primitivos para APIs, listados y paneles.
 *
 * `loadOrder` es el orden de carga (inverso al de entrega): lo deriva PlanView a partir de la secuencia.
 */
final readonly class PlannedStopView
{
    public function __construct(
        public string $id,
        public string $shipmentId,
        public int $sequence,
        public ?string $etaAt,
        public ?int $etaMinutes,
        public ?string $lane,
        public int $loadOrder,
        public ?string $orderReference = null,
        public ?string $customerName = null,
        public ?string $address = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'order_reference' => $this->orderReference,
            'customer_name' => $this->customerName,
            'address' => $this->address,
            'sequence' => $this->sequence,
            'eta_at' => $this->etaAt,
            'eta_minutes' => $this->etaMinutes,
            'lane' => $this->lane,
            'load_order' => $this->loadOrder,
        ];
    }
}
