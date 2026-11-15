<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\ListCities;

use Logistics\Shared\Application\Query\Query;

/**
 * Catálogo de ciudades (los nueve departamentos de Bolivia) con su almacén central, si lo tienen. Sin
 * filtros: la lista es cerrada y siempre devuelve las nueve.
 */
final readonly class ListCitiesQuery implements Query {}
