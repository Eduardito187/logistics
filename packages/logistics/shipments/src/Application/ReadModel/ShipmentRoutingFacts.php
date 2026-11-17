<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Datos del envío que necesita la construcción del itinerario (BuildShipmentItinerary): sus extremos
 * (nodo/departamento de origen y de destino) y la coordenada del cliente para la entrega a domicilio.
 *
 * Solo primitivos: lo arma el lado de lectura directo desde la fila del envío, sin reconstituir el agregado.
 */
final readonly class ShipmentRoutingFacts
{
    public function __construct(
        public string $shipmentId,
        public ?string $originNodeId,
        public ?string $destinationNodeId,
        public ?string $originDepartment,
        public ?string $destinationDepartment,
        public ?float $lat,
        public ?float $lng,
    ) {}

    /**
     * Coordenada del cliente en el shape que espera el router, o null si el envío no tiene lat/lng.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function customerGeo(): ?array
    {
        if ($this->lat === null || $this->lng === null) {
            return null;
        }

        return ['lat' => $this->lat, 'lng' => $this->lng];
    }
}
