<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

/**
 * Un pedido candidato a sumarse al viaje, con cada dato que el planificador necesita leer sin abrirlo:
 * pedido, cliente, dirección corta, modalidad, unidades, a qué distancia está del punto de referencia, la
 * hora que eligió el cliente, si entra en el camión y si se recomienda sumarlo ahora (con su motivo).
 *
 * Los motivos son textos en español para mostrar tal cual; nunca llevan identificadores internos.
 */
final readonly class TripCandidateView
{
    /**
     * @param array{from: string, to: string}|null $timeWindow
     */
    public function __construct(
        public string $shipmentId,
        public ?string $orderReference,
        public ?string $customerName,
        public ?string $shortAddress,
        public string $deliveryMode,
        public int $unitsTotal,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $distanceKmFromAnchor,
        public ?array $timeWindow,
        public float $weightKg,
        public float $volumeM3,
        public bool $fits,
        public ?string $fitsReason,
        public bool $suggested,
        public ?string $suggestedReason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'order_reference' => $this->orderReference,
            'customer_name' => $this->customerName,
            'short_address' => $this->shortAddress,
            'delivery_mode' => $this->deliveryMode,
            'units_total' => $this->unitsTotal,
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'distance_km_from_anchor' => $this->distanceKmFromAnchor,
            'time_window' => $this->timeWindow,
            'weight_kg' => round($this->weightKg, 1),
            'volume_m3' => round($this->volumeM3, 3),
            'fits' => $this->fits,
            'fits_reason' => $this->fitsReason,
            'suggested' => $this->suggested,
            'suggested_reason' => $this->suggestedReason,
        ];
    }
}
