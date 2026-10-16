<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Inbound\Application\Query\GetReceivingDockCalendar\GetReceivingDockCalendar;
use Logistics\Inbound\Application\ReadModel\ReceivingDockDayView;
use Logistics\Inbound\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el calendario de andén de recepción (GET /v1/inbound/receiving-calendar): por día y franja,
 * cuántas citas hay agendadas.
 */
final class GetReceivingCalendarController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $from = $request->string('from')->toString();
            if ($from === '') {
                $from = date('Y-m-d');
            }
            $days = (int) $request->integer('days', 7);
            $warehouseId = $request->string('warehouse_id')->toString();

            /** @var list<ReceivingDockDayView> $calendar */
            $calendar = $this->queries->ask(new GetReceivingDockCalendar(
                fromDate: $from,
                days: $days > 0 ? $days : 7,
                warehouseId: $warehouseId !== '' ? $warehouseId : null,
            ));

            return $this->data(array_map(
                static fn (ReceivingDockDayView $day): array => $day->toArray(),
                $calendar,
            ));
        });
    }
}
