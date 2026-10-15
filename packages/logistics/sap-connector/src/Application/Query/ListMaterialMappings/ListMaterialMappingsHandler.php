<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Query\ListMaterialMappings;

use Logistics\SapConnector\Application\ReadModel\MaterialMappingView;
use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\MaterialMapping;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el mapa de traducción completo como read models.
 *
 * @implements QueryHandler<ListMaterialMappings, list<MaterialMappingView>>
 */
final class ListMaterialMappingsHandler implements QueryHandler
{
    public function __construct(
        private readonly MaterialMappingRepository $mappings,
    ) {}

    /**
     * @return list<MaterialMappingView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListMaterialMappings) {
            throw SapConnectorException::because('ListMaterialMappingsHandler solo maneja ListMaterialMappings');
        }

        return array_map(
            static fn (MaterialMapping $mapping): MaterialMappingView => MaterialMappingView::fromMapping($mapping),
            $this->mappings->all(),
        );
    }
}
