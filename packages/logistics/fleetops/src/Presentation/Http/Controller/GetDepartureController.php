<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\GetDeparture\GetDeparture;
use Logistics\FleetOps\Application\ReadModel\DepartureView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve una salida por su id (consulta GetDeparture). Responde 404 si no existe.
 */
final class GetDepartureController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $departureId): JsonResponse
    {
        return $this->guard(function () use ($departureId): JsonResponse {
            /** @var DepartureView|null $departure */
            $departure = $this->queries->ask(new GetDeparture(departureId: $departureId));

            if ($departure === null) {
                return $this->error('departure_not_found', 'No departure found for the given id.', 404);
            }

            return $this->data($departure->toArray());
        });
    }
}
