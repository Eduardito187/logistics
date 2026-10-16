<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Returns\Application\Query\ListReturns\ListReturns;
use Logistics\Returns\Application\ReadModel\ReturnView;
use Logistics\Returns\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista devoluciones (GET /v1/returns), con filtros opcionales por seller y estado.
 */
final class ListReturnsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $sellerId = $request->query('seller_id');
            $status = $request->query('status');

            /** @var list<ReturnView> $returns */
            $returns = $this->queries->ask(new ListReturns(
                sellerId: is_string($sellerId) && $sellerId !== '' ? $sellerId : null,
                status: is_string($status) && $status !== '' ? $status : null,
            ));

            return $this->data(array_map(static fn (ReturnView $view): array => $view->toArray(), $returns));
        });
    }
}
