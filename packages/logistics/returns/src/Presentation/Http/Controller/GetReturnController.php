<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Returns\Application\Query\GetReturn\GetReturn;
use Logistics\Returns\Application\ReadModel\ReturnView;
use Logistics\Returns\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve una devolución por su id (GET /v1/returns/{returnId}), o 404 si no existe.
 */
final class GetReturnController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $returnId): JsonResponse
    {
        return $this->guard(function () use ($returnId): JsonResponse {
            /** @var ReturnView|null $return */
            $return = $this->queries->ask(new GetReturn(returnId: $returnId));

            if ($return === null) {
                return $this->error('not_found', 'Return not found.', 404);
            }

            return $this->data($return->toArray());
        });
    }
}
