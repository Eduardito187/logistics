<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Presentation\Http\Request\CapacityCalendarRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Calendario de cupos de una fecha: todos los buckets de ese día (todos los scopes, franjas y
 * unidades). Solo lectura; llama al QueryBus.
 */
final class ListCapacityCalendarController
{
    public function __invoke(CapacityCalendarRequest $request, QueryBus $bus): JsonResponse
    {
        $date = $request->calendarDate();

        /** @var list<CapacityView> $views */
        $views = $bus->ask(new ListBucketsByDateQuery($date));

        $data = array_map(static fn (CapacityView $view): array => $view->toArray(), $views);

        return new JsonResponse([
            'data' => $data,
            'meta' => ['date' => $date, 'count' => count($data)],
        ]);
    }
}
