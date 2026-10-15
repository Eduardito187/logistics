<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Carriers\Application\Command\RateCarrier\RateCarrier;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Carriers\Presentation\Http\Request\RateCarrierRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra una calificación de desempeño de un transportista (comando RateCarrier).
 *
 * El id del transportista llega por la ruta; la calificación (1 a 5) por el cuerpo.
 */
final class RateCarrierController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RateCarrierRequest $request, string $carrierId): JsonResponse
    {
        return $this->guard(function () use ($request, $carrierId): JsonResponse {
            $this->commands->dispatch(new RateCarrier(
                carrierId: $carrierId,
                rating: $request->integer('rating'),
            ));

            return $this->accepted([
                'carrier_id' => $carrierId,
                'rating' => $request->integer('rating'),
            ]);
        });
    }
}
