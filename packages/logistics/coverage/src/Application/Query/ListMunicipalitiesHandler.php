<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Query;

use Logistics\Coverage\Application\Contract\MunicipalityCatalog;
use Logistics\Coverage\Application\ReadModel\MunicipalityView;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista los municipios como modelos de lectura, con su departamento resuelto, ordenados por nombre.
 *
 * @implements QueryHandler<ListMunicipalities, array<int, MunicipalityView>>
 */
final readonly class ListMunicipalitiesHandler implements QueryHandler
{
    public function __construct(private MunicipalityCatalog $catalog) {}

    /**
     * @return array<int, MunicipalityView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListMunicipalities);

        return $this->catalog->all();
    }
}
