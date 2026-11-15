<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Network\Application\Contract\CityCatalogReader;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use stdClass;

/**
 * Lector del catálogo de ciudades sobre `network_nodes` con el query builder: una sola consulta de las
 * columnas necesarias, sin hidratar agregados. La red tiene pocos nodos (decenas a cientos), así que se
 * leen de una vez y el handler los agrupa por departamento.
 */
final readonly class DbCityCatalogReader implements CityCatalogReader
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function activeNodes(): array
    {
        $rows = $this->connections->connection()->table('network_nodes')
            ->where('status', NodeStatus::Active->value)
            ->whereNotNull('department')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'department']);

        $nodes = [];
        foreach ($rows as $row) {
            if (!$row instanceof stdClass) {
                continue;
            }

            $nodes[] = [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'department' => (string) $row->department,
            ];
        }

        return $nodes;
    }
}
