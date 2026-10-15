<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Carriers\Application\Command\SuspendCarrier\SuspendCarrier;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Suspende a un transportista para que deje de recibir asignaciones (comando SuspendCarrier).
 *
 * El id del transportista llega por la ruta. El comando es idempotente en el dominio.
 */
final class SuspendCarrierController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $carrierId): JsonResponse
    {
        return $this->guard(function () use ($carrierId): JsonResponse {
            $this->commands->dispatch(new SuspendCarrier(carrierId: $carrierId));

            return $this->accepted(['carrier_id' => $carrierId]);
        });
    }
}
