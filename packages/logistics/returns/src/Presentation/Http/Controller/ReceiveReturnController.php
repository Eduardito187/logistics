<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Returns\Application\Command\ReceiveReturn\ReceiveReturn;
use Logistics\Returns\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra la recepción física de una devolución (POST /v1/returns/{returnId}/receive). 202 de acuse.
 */
final class ReceiveReturnController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $returnId): JsonResponse
    {
        return $this->guard(function () use ($returnId): JsonResponse {
            $this->commands->dispatch(new ReceiveReturn(returnId: $returnId));

            return $this->accepted(['return_id' => $returnId]);
        });
    }
}
