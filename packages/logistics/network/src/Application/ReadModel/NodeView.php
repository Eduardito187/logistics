<?php

declare(strict_types=1);

namespace Logistics\Network\Application\ReadModel;

use Logistics\Network\Domain\Node;

/**
 * Modelo de lectura de un nodo: forma plana y primitiva, lista para serializar a JSON o para la API.
 *
 * No expone objetos de dominio; si se cachea, se cachea su toArray() (regla 7 del proyecto).
 */
final readonly class NodeView
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $type,
        public float $latitude,
        public float $longitude,
        public string $status,
    ) {}

    public static function fromNode(Node $node): self
    {
        return new self(
            $node->id()->toString(),
            $node->code()->toString(),
            $node->name(),
            $node->type()->value,
            $node->location()->latitude,
            $node->location()->longitude,
            $node->status()->value,
        );
    }

    /**
     * @return array{id: string, code: string, name: string, type: string, lat: float, lng: float, status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'status' => $this->status,
        ];
    }
}
