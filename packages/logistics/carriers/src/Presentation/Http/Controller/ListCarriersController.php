<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Carriers\Application\Query\ListCarriers\ListCarriers;
use Logistics\Carriers\Application\ReadModel\CarrierView;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los transportistas como proyecciones de lectura, con filtro opcional por estado
 * (consulta ListCarriers).
 */
final class ListCarriersController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $status = $request->query('status');
            $status = is_string($status) && $status !== '' ? $status : null;

            /** @var list<CarrierView> $carriers */
            $carriers = $this->queries->ask(new ListCarriers(status: $status));

            return $this->data(
                array_map(static fn (CarrierView $view): array => $view->toArray(), $carriers),
            );
        });
    }
}
