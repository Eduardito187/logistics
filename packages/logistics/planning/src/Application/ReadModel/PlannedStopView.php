<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

/**
 * Modelo de lectura de una parada del plan: forma plana en primitivos para APIs, listados y paneles.
 *
 * `loadOrder` es el orden de carga (inverso al de entrega): lo deriva PlanView a partir de la secuencia.
 *
 * Datos legibles del envío (para que la app nunca muestre un identificador interno): referencia del
 * pedido, cliente, dirección corta, modalidad de entrega, unidades y la hora que eligió el cliente. Los
 * resuelve EN LOTE el PlanViewAssembler; quedan en null si el envío no se pudo leer.
 */
final readonly class PlannedStopView
{
    /**
     * @param array{from: string, to: string}|null $timeWindow hora elegida por el cliente (ISO-8601)
     */
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
        public ?string $shortAddress = null,
        public ?string $deliveryMode = null,
        public ?int $unitsTotal = null,
        public ?array $timeWindow = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            ...$this->readableFields(),
            'address' => $this->address,
            'sequence' => $this->sequence,
            'eta_at' => $this->etaAt,
            'eta_minutes' => $this->etaMinutes,
            'lane' => $this->lane,
            'load_order' => $this->loadOrder,
        ];
    }

    /**
     * Campos legibles del envío, compartidos por la parada del plan y por su ETA.
     *
     * @return array{order_reference: string|null, customer_name: string|null, short_address: string|null, delivery_mode: string|null, units_total: int|null, time_window: array{from: string, to: string}|null}
     */
    public function readableFields(): array
    {
        return [
            'order_reference' => $this->orderReference,
            'customer_name' => $this->customerName,
            'short_address' => $this->shortAddress,
            'delivery_mode' => $this->deliveryMode,
            'units_total' => $this->unitsTotal,
            'time_window' => $this->timeWindow,
        ];
    }
}
