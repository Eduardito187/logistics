<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\ListCities;

use Logistics\Network\Application\Contract\CityCatalogReader;
use Logistics\Network\Application\ReadModel\CityView;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\NodeType;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Arma el catálogo de ciudades: los nueve departamentos SIEMPRE (aunque no tengan nodos), cada uno con su
 * almacén central y la cantidad de nodos activos.
 *
 * Almacén central de una ciudad = el nodo activo de tipo centro de distribución o almacén central de ese
 * departamento; si hay varios, el primero por código (el lector ya los entrega ordenados). No todas las
 * ciudades tienen uno: en ese caso va null.
 *
 * @implements QueryHandler<ListCitiesQuery, list<CityView>>
 */
final readonly class ListCitiesHandler implements QueryHandler
{
    public function __construct(
        private CityCatalogReader $reader,
    ) {}

    /**
     * @return list<CityView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListCitiesQuery);

        /** @var array<string, int> $counts */
        $counts = [];
        /** @var array<string, array{node_id: string, code: string, name: string}> $warehouses */
        $warehouses = [];

        foreach ($this->reader->activeNodes() as $node) {
            $department = Department::tryFromText($node['department']);
            if ($department === null) {
                continue;
            }

            $code = $department->value;
            $counts[$code] = ($counts[$code] ?? 0) + 1;

            $type = NodeType::tryFromLoose($node['type']);
            $isWarehouse = $type === NodeType::DistributionCenter || $type === NodeType::CentralWarehouse;
            if ($isWarehouse && !isset($warehouses[$code])) {
                $warehouses[$code] = ['node_id' => $node['id'], 'code' => $node['code'], 'name' => $node['name']];
            }
        }

        $cities = [];
        foreach (Department::cases() as $department) {
            $cities[] = new CityView(
                $department->value,
                $department->label(),
                $warehouses[$department->value] ?? null,
                $counts[$department->value] ?? 0,
            );
        }

        return $cities;
    }
}
