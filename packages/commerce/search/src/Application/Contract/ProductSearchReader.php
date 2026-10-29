<?php

declare(strict_types=1);

namespace Commerce\Search\Application\Contract;

use Commerce\Search\Application\ReadModel\CategoryFacet;
use Commerce\Search\Application\ReadModel\SearchPage;
use Commerce\Search\Domain\ValueObject\SearchCriteria;

/**
 * Puerto de lectura del catálogo para búsqueda. Lo implementa Infrastructure contra la tabla read-only
 * `commerce_products` (del contexto `catalog`), sin acoplarse a sus clases. Si la fuente no existe, el
 * adaptador degrada a resultado vacío (cross-context sin dependencia composer, patrón del skill).
 */
interface ProductSearchReader
{
    /**
     * Ejecuta la búsqueda y devuelve una página con paginación por cursor (keyset).
     */
    public function search(SearchCriteria $criteria): SearchPage;

    /**
     * Cuenta productos por categoría (faceta de navegación) para el estado dado.
     *
     * @return list<CategoryFacet>
     */
    public function facetsByCategory(string $status): array;
}
