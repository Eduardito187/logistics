<?php

declare(strict_types=1);

namespace Logistics\Settlement\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Settlement\Application\Query\ListSettlements\ListSettlements;
use Logistics\Settlement\Application\ReadModel\SettlementView;
use Logistics\Settlement\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista las liquidaciones de sellers como proyecciones de lectura, con filtros opcionales por seller
 * (?seller_id=), estado (?status=) y periodo (?period_from=&period_to=) (consulta ListSettlements).
 */
final class ListSettlementsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<SettlementView> $settlements */
            $settlements = $this->queries->ask(new ListSettlements(
                sellerId: $this->nullableQuery($request, 'seller_id'),
                status: $this->nullableQuery($request, 'status'),
                periodFrom: $this->nullableQuery($request, 'period_from'),
                periodTo: $this->nullableQuery($request, 'period_to'),
            ));

            return $this->data(
                array_map(static fn (SettlementView $view): array => $view->toArray(), $settlements),
            );
        });
    }

    private function nullableQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
