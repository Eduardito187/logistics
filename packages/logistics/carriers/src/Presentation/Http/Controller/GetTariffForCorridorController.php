<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Carriers\Application\Query\GetTariffForCorridor\GetTariffForCorridor;
use Logistics\Carriers\Application\ReadModel\TariffView;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve la tarifa de un transportista para un corredor y clase de vehículo
 * (consulta GetTariffForCorridor). Responde 404 si no existe tarifa.
 */
final class GetTariffForCorridorController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request, string $carrierId): JsonResponse
    {
        return $this->guard(function () use ($request, $carrierId): JsonResponse {
            /** @var TariffView|null $tariff */
            $tariff = $this->queries->ask(new GetTariffForCorridor(
                carrierId: $carrierId,
                corridorFrom: (string) $request->query('corridor_from', ''),
                corridorTo: (string) $request->query('corridor_to', ''),
                corridorType: (string) $request->query('corridor_type', ''),
                vehicleClass: (string) $request->query('vehicle_class', ''),
            ));

            if ($tariff === null) {
                return $this->error('tariff_not_found', 'No tariff found for the given corridor and vehicle class.', 404);
            }

            return $this->data($tariff->toArray());
        });
    }
}
