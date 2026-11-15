<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * De dónde sale y a qué departamento va un envío, en forma legible: nodo de origen (con su nombre) y
 * códigos de departamento de origen y destino (`la_paz`, `santa_cruz`, …). Cualquier dato puede faltar
 * (envío todavía sin alcance resuelto).
 */
final readonly class ShipmentOriginView
{
    public function __construct(
        public ?string $originNodeId = null,
        public ?string $originNodeName = null,
        public ?string $originDepartment = null,
        public ?string $destinationDepartment = null,
    ) {}

    /**
     * @return array{origin_node: array{id: string, name: string|null}|null, origin_department: string|null, destination_department: string|null}
     */
    public function toArray(): array
    {
        return [
            'origin_node' => $this->originNodeId !== null
                ? ['id' => $this->originNodeId, 'name' => $this->originNodeName]
                : null,
            'origin_department' => $this->originDepartment,
            'destination_department' => $this->destinationDepartment,
        ];
    }
}
