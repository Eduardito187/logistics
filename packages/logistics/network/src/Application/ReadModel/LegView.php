<?php

declare(strict_types=1);

namespace Logistics\Network\Application\ReadModel;

use Logistics\Network\Domain\Leg;

/**
 * Modelo de lectura de un tramo/corredor: forma plana y primitiva, lista para serializar a JSON o
 * para la API.
 *
 * No expone objetos de dominio; si se cachea, se cachea su toArray() (regla 7 del proyecto).
 */
final readonly class LegView
{
    public function __construct(
        public string $id,
        public string $fromNodeId,
        public string $toNodeId,
        public string $corridorType,
    ) {}

    public static function fromLeg(Leg $leg): self
    {
        return new self(
            $leg->id()->toString(),
            $leg->from()->toString(),
            $leg->to()->toString(),
            $leg->corridor()->value,
        );
    }

    /**
     * @return array{id: string, from_node_id: string, to_node_id: string, corridor_type: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from_node_id' => $this->fromNodeId,
            'to_node_id' => $this->toNodeId,
            'corridor_type' => $this->corridorType,
        ];
    }
}
