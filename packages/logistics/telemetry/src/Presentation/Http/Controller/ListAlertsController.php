<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Telemetry\Application\Query\CountAlerts\CountAlerts;
use Logistics\Telemetry\Application\Query\ListAlerts\ListAlerts;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use Logistics\Telemetry\Presentation\Http\ApiController;
use Throwable;

/**
 * Lista alertas con filtros opcionales por vehículo (?vehicle_id), tipo (?type), estado de reconocimiento
 * (?acknowledged=true|false) y rango de ocurrencia (?from&to, ISO 8601, inclusive) (consulta ListAlerts).
 *
 * Tabla de crecimiento ilimitado: paginación OBLIGATORIA (per_page por defecto 50, máx. 200), con recorte en
 * SQL (LIMIT/OFFSET + COUNT(*) aparte) y el `meta` estándar del proyecto (page/per_page/total/total_pages/
 * next_page, o cursor). Aditivo: `meta.unacknowledged_total` (sin reconocer con los mismos filtros de vehículo,
 * tipo y rango) y `plate` / `driver_name` por alerta (resueltos en lote, sin N+1).
 */
final class ListAlertsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $from = $this->date($request, 'from');
            $to = $this->date($request, 'to');
        } catch (Throwable) {
            return $this->error('invalid_range', 'The from/to parameters must be ISO 8601 date-times.', 422);
        }

        return $this->guard(function () use ($request, $from, $to): JsonResponse {
            $vehicleId = $request->query('vehicle_id');
            $vehicleId = is_string($vehicleId) && $vehicleId !== '' ? $vehicleId : null;
            $type = $request->query('type');
            $type = is_string($type) && $type !== '' ? $type : null;

            $acknowledged = null;
            if ($request->has('acknowledged')) {
                $acknowledged = $request->boolean('acknowledged');
            }

            /** @var Page<AlertView> $page */
            $page = $this->queries->ask(new ListAlerts(
                vehicleId: $vehicleId,
                type: $type,
                acknowledged: $acknowledged,
                limit: ApiPagination::limit($request),
                offset: ApiPagination::offset($request),
                from: $from,
                to: $to,
                enrich: true,
            ));

            /** @var int $unacknowledged */
            $unacknowledged = $this->queries->ask(new CountAlerts($vehicleId, $type, false, $from, $to));

            $items = array_map(static fn (AlertView $view): array => $view->toArray(), $page->items);

            $body = ApiPagination::envelope($items, $page->total, $request);
            $body['meta']['unacknowledged_total'] = $unacknowledged;

            return new JsonResponse($body);
        });
    }

    /**
     * Fecha opcional de la query (ISO 8601). Un '+' sin codificar llega como espacio: se restituye.
     */
    private function date(Request $request, string $key): ?DateTimeImmutable
    {
        $raw = $request->query($key);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return new DateTimeImmutable(str_replace(' ', '+', trim($raw)));
    }
}
