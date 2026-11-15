<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Network\Application\Query\ListCities\ListCitiesQuery;
use Logistics\Network\Application\ReadModel\CityView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Catálogo de ciudades (departamentos) para la app interna: sirve para elegir la ciudad de una persona y
 * para saber cuáles tienen almacén central. Solo lectura, para cualquier rol autenticado de la app.
 * Llama al QueryBus (nunca a Infrastructure ni al dominio).
 */
final class IndexCityController
{
    public function __invoke(QueryBus $bus): JsonResponse
    {
        /** @var list<CityView> $cities */
        $cities = $bus->ask(new ListCitiesQuery);

        return new JsonResponse([
            'data' => array_map(static fn (CityView $city): array => $city->toArray(), $cities),
        ]);
    }
}
