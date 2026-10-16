<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Returns\Application\Command\DispositionReturn\DispositionReturn;
use Logistics\Returns\Presentation\Http\ApiController;
use Logistics\Returns\Presentation\Http\Request\DispositionReturnRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Decide la disposición de una devolución recibida (POST /v1/returns/{returnId}/disposition). Cuando es
 * `restock`, el evento resultante lleva lo que inventory necesita para re-ingresar el stock. 202 de acuse.
 */
final class DispositionReturnController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(DispositionReturnRequest $request, string $returnId): JsonResponse
    {
        return $this->guard(function () use ($request, $returnId): JsonResponse {
            $this->commands->dispatch(new DispositionReturn(
                returnId: $returnId,
                disposition: $request->string('disposition')->toString(),
            ));

            return $this->accepted(['return_id' => $returnId]);
        });
    }
}
