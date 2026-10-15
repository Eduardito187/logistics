<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Network\Application\Query\ListLegs\ListLegsQuery;
use Logistics\Network\Application\ReadModel\LegView;
use Logistics\Network\Presentation\Http\Request\IndexLegRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los tramos/corredores de la red para la app interna (puerta app, solo lectura).
 *
 * Llama al QueryBus (nunca a Infrastructure ni al dominio). La consulta devuelve todos los tramos y
 * el filtrado por corredor se aplica acá, sobre los modelos de lectura, porque el volumen es chico.
 */
final class IndexLegController
{
    public function __invoke(IndexLegRequest $request, QueryBus $bus): JsonResponse
    {
        /** @var list<LegView> $legs */
        $legs = $bus->ask(new ListLegsQuery);

        $corridor = $request->corridorFilter();

        $filtered = array_values(array_filter(
            $legs,
            static fn (LegView $leg): bool => $corridor === null || $leg->corridorType === $corridor,
        ));

        $data = array_map(static fn (LegView $leg): array => $leg->toArray(), $filtered);

        return new JsonResponse([
            'data' => $data,
            'meta' => ['count' => count($data)],
        ]);
    }
}
