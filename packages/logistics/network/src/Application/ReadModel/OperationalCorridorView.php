<?php

declare(strict_types=1);

namespace Logistics\Network\Application\ReadModel;

use Logistics\Network\Domain\ValueObject\OperationalCorridor;

/**
 * Modelo de lectura de un corredor operativo en un departamento: forma plana y primitiva, lista para
 * serializar a JSON o para que planning/promise la consuman. Incluye el nodo de origen y, cuando el
 * corredor une dos nodos, el de destino (null en los corredores terminales).
 *
 * No expone objetos de dominio; si se cachea, se cachea su toArray() (regla 7 del proyecto).
 */
final readonly class OperationalCorridorView
{
    public function __construct(
        public string $corridorType,
        public string $department,
        public string $originNodeId,
        public string $originNodeCode,
        public string $originNodeName,
        public string $originNodeType,
        public ?string $destinationNodeId,
        public ?string $destinationNodeCode,
        public ?string $destinationNodeName,
        public ?string $destinationNodeType,
    ) {}

    public static function fromCorridor(OperationalCorridor $corridor): self
    {
        $origin = $corridor->origin;
        $destination = $corridor->destination;

        return new self(
            $corridor->corridor->value,
            $corridor->department->value,
            $origin->id()->toString(),
            $origin->code()->toString(),
            $origin->name(),
            $origin->type()->value,
            $destination?->id()->toString(),
            $destination?->code()->toString(),
            $destination?->name(),
            $destination?->type()->value,
        );
    }

    /**
     * @return array{
     *     corridor_type: string,
     *     department: string,
     *     origin_node_id: string,
     *     origin_node_code: string,
     *     origin_node_name: string,
     *     origin_node_type: string,
     *     destination_node_id: string|null,
     *     destination_node_code: string|null,
     *     destination_node_name: string|null,
     *     destination_node_type: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'corridor_type' => $this->corridorType,
            'department' => $this->department,
            'origin_node_id' => $this->originNodeId,
            'origin_node_code' => $this->originNodeCode,
            'origin_node_name' => $this->originNodeName,
            'origin_node_type' => $this->originNodeType,
            'destination_node_id' => $this->destinationNodeId,
            'destination_node_code' => $this->destinationNodeCode,
            'destination_node_name' => $this->destinationNodeName,
            'destination_node_type' => $this->destinationNodeType,
        ];
    }
}
