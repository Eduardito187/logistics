<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Telemetry\Application\Query\ListAlerts\ListAlerts;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * Lista alertas con filtros opcionales por vehículo (?vehicle_id), tipo (?type) y estado de
 * reconocimiento (?acknowledged=true|false) (consulta ListAlerts).
 *
 * Tabla de crecimiento ilimitado: paginación OBLIGATORIA (per_page por defecto 50), con recorte en SQL
 * (LIMIT/OFFSET + COUNT(*) aparte).
 */
final class ListAlertsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $vehicleId = $request->query('vehicle_id');
            $type = $request->query('type');

            $acknowledged = null;
            if ($request->has('acknowledged')) {
                $acknowledged = $request->boolean('acknowledged');
            }

            /** @var Page<AlertView> $page */
            $page = $this->queries->ask(new ListAlerts(
                vehicleId: is_string($vehicleId) && $vehicleId !== '' ? $vehicleId : null,
                type: is_string($type) && $type !== '' ? $type : null,
                acknowledged: $acknowledged,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
            ));

            $items = array_map(static fn (AlertView $view): array => $view->toArray(), $page->items);

            return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
        });
    }
}
