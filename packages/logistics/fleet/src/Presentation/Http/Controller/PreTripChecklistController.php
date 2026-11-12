<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Command\ConductPreTripChecklist\ConductPreTripChecklist;
use Logistics\Fleet\Application\Query\GetLatestPreTripChecklist\GetLatestPreTripChecklist;
use Logistics\Fleet\Application\Query\GetVehicleFitness\GetVehicleFitness;
use Logistics\Fleet\Application\Query\ListPreTripChecklists\ListPreTripChecklists;
use Logistics\Fleet\Application\ReadModel\PreTripChecklistView;
use Logistics\Fleet\Application\ReadModel\VehicleFitnessView;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Endpoints HTTP del checklist pre-salida. Habla solo con los buses CQRS.
 *
 * - index: lectura de las inspecciones (más recientes primero), filtrable por vehículo, resultado y rango
 *   de fechas; paginación estándar opt-in (`page`/`per_page` o `cursor`, ver ApiPagination).
 * - latest: último checklist de un vehículo (el recordatorio de la app ya no baja el historial).
 * - store: el chofer registra el checklist ejecutado; responde si el vehículo quedó apto para despachar.
 */
final readonly class PreTripChecklistController
{
    use RespondsWithEnvelope;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    /**
     * GET /fleet/pre-trip-checklists — lista las inspecciones pre-salida.
     *
     * Sin parámetros de paginación devuelve la colección completa con `meta.count` (contrato histórico);
     * con `page`/`per_page`/`cursor` pagina en SQL y devuelve el `meta` estándar del proyecto.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['sometimes', 'uuid'],
            'only_passed' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $onlyPassed = $request->has('only_passed') ? $request->boolean('only_passed') : null;
        $paginate = ApiPagination::requested($request);

        $result = $this->queryBus->ask(new ListPreTripChecklists(
            vehicleId: $validated['vehicle_id'] ?? null,
            onlyPassed: $onlyPassed,
            from: $validated['from'] ?? null,
            to: $validated['to'] ?? null,
            limit: $paginate ? ApiPagination::limit($request) : null,
            offset: $paginate ? ApiPagination::offset($request) : null,
        ));

        if ($result instanceof Page) {
            /** @var Page<PreTripChecklistView> $result */
            $items = array_map(static fn (PreTripChecklistView $view): array => $view->toArray(), $result->items);

            return new JsonResponse(ApiPagination::envelope($items, $result->total, $request));
        }

        /** @var list<PreTripChecklistView> $result */
        return $this->respondCollection(array_map(
            static fn (PreTripChecklistView $view): array => $view->toArray(),
            $result,
        ));
    }

    /**
     * GET /fleet/pre-trip-checklists/latest?vehicle_id= — último checklist del vehículo (404 si no hay).
     */
    public function latest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid'],
        ]);

        /** @var PreTripChecklistView|null $view */
        $view = $this->queryBus->ask(new GetLatestPreTripChecklist($validated['vehicle_id']));

        if ($view === null) {
            return ProblemDetails::fromCode(
                ErrorCode::NotFound,
                'El vehículo no tiene checklists pre-salida registrados.',
            )->withInstance($request->getRequestUri())->toResponse($request);
        }

        return $this->respondData($view->toArray());
    }

    /**
     * POST /fleet/pre-trip-checklists — registra un checklist pre-salida ejecutado.
     *
     * Aditivo (#27): además del acuse devuelve `fit_to_dispatch` (el checklist no tiene fallas críticas Y
     * el vehículo está apto en esa fecha), los ítems críticos fallidos/faltantes y la aptitud del vehículo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid'],
            'driver_id' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['boolean'],
            'performed_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        /** @var array<string, bool> $items */
        $items = array_map(static fn ($checked): bool => (bool) $checked, $validated['items']);

        $this->commandBus->dispatch(new ConductPreTripChecklist(
            vehicleId: $validated['vehicle_id'],
            driverId: $validated['driver_id'],
            items: $items,
            performedOn: $validated['performed_on'],
            notes: $validated['notes'] ?? null,
        ));

        // La inspección recién creada es la de mayor id (UUID v7) del vehículo en esa fecha.
        $created = $this->queryBus->ask(new ListPreTripChecklists(
            vehicleId: $validated['vehicle_id'],
            from: $validated['performed_on'],
            to: $validated['performed_on'],
            limit: 1,
            offset: 0,
        ));
        $inspection = $created instanceof Page ? ($created->items[0] ?? null) : null;
        $inspection = $inspection instanceof PreTripChecklistView ? $inspection : null;

        /** @var VehicleFitnessView|null $fitness */
        $fitness = $this->queryBus->ask(new GetVehicleFitness($validated['vehicle_id'], $validated['performed_on']));

        $checklistFit = $inspection === null || $inspection->fitToDispatch;
        $vehicleFit = $fitness?->fitToDispatch;

        return $this->respondAccepted([
            'vehicle_id' => $validated['vehicle_id'],
            'driver_id' => $validated['driver_id'],
            'performed_on' => $validated['performed_on'],
            'inspection_id' => $inspection?->id,
            'passed' => $inspection?->passed,
            'fit_to_dispatch' => $checklistFit && $vehicleFit !== false,
            'failed_critical' => $inspection !== null ? $inspection->failedCritical : [],
            'missing_critical' => $inspection !== null ? $inspection->missingCritical : [],
            'vehicle_fitness' => $fitness?->toArray(),
        ]);
    }
}
