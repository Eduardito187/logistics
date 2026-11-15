<?php

declare(strict_types=1);

namespace Logistics\Network\Application\ReadModel;

/**
 * Modelo de lectura de una ciudad (departamento de Bolivia) del catálogo: su código, su nombre legible,
 * su almacén central si lo tiene y cuántos nodos activos hay en ella. Forma plana y primitiva; si se
 * cachea, se cachea su toArray() (regla 7 del proyecto).
 */
final readonly class CityView
{
    /**
     * @param array{node_id: string, code: string, name: string}|null $centralWarehouse
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?array $centralWarehouse,
        public int $nodesCount,
    ) {}

    /**
     * @return array{code: string, name: string, central_warehouse: array{node_id: string, code: string, name: string}|null, nodes_count: int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'central_warehouse' => $this->centralWarehouse,
            'nodes_count' => $this->nodesCount,
        ];
    }
}
