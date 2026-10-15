<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Command\CloseDeparture\CloseDeparture;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\CloseDepartureRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cierra una salida al retornar el vehículo (comando CloseDeparture). El id llega por la ruta; el
 * odómetro de regreso y la fecha por el cuerpo. El dominio valida que el odómetro no retroceda.
 */
final class CloseDepartureController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(CloseDepartureRequest $request, string $departureId): JsonResponse
    {
        return $this->guard(function () use ($request, $departureId): JsonResponse {
            $this->commands->dispatch(new CloseDeparture(
                departureId: $departureId,
                odometerInKm: $request->integer('odometer_in_km'),
                returnedAt: $request->string('returned_at')->toString(),
            ));

            return $this->accepted(['departure_id' => $departureId]);
        });
    }
}
