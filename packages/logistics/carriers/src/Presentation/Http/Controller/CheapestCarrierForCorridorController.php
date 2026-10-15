<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Carriers\Application\Query\CheapestCarrierForCorridor\CheapestCarrierForCorridor;
use Logistics\Carriers\Application\ReadModel\TariffView;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve la tarifa más barata disponible para un corredor (consulta CheapestCarrierForCorridor),
 * usada por planning para consolidar por costo. Responde 404 si no hay ninguna tarifa.
 */
final class CheapestCarrierForCorridorController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var TariffView|null $tariff */
            $tariff = $this->queries->ask(new CheapestCarrierForCorridor(
                corridorFrom: (string) $request->query('corridor_from', ''),
                corridorTo: (string) $request->query('corridor_to', ''),
                corridorType: (string) $request->query('corridor_type', ''),
            ));

            if ($tariff === null) {
                return $this->error('tariff_not_found', 'No tariff found for the given corridor.', 404);
            }

            return $this->data($tariff->toArray());
        });
    }
}
