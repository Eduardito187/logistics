<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\ReadModel;

/**
 * Modelo de lectura de un envío en la cola "Unslotted": tiene fecha de entrega solicitada pero todavía sin
 * ventana asignada. Forma plana en primitivos para la cola de operaciones y la página admin. Lleva el id
 * del envío (dato operativo, no presentación) y la fecha comprometida.
 */
final readonly class UnslottedShipmentView
{
    public function __construct(
        public string $shipmentId,
        public string $date,
    ) {}

    /**
     * @return array{shipment_id: string, date: string}
     */
    public function toArray(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'date' => $this->date,
        ];
    }
}
