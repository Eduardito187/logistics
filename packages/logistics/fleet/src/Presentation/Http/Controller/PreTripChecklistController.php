<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Command\ConductPreTripChecklist\ConductPreTripChecklist;
use Logistics\Fleet\Application\Query\ListPreTripChecklists\ListPreTripChecklists;
use Logistics\Fleet\Application\ReadModel\PreTripChecklistView;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Endpoints HTTP del checklist pre-salida. Habla solo con los buses CQRS.
 *
 * - index: lectura de las inspecciones (filtrable por vehículo o resultado) para la app y el tablero.
 * - store: el chofer registra el checklist ejecutado antes de salir a ruta (app interna).
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
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['sometimes', 'uuid'],
            'only_passed' => ['sometimes', 'boolean'],
        ]);

        $onlyPassed = $request->has('only_passed') ? $request->boolean('only_passed') : null;

        /** @var list<PreTripChecklistView> $checklists */
        $checklists = $this->queryBus->ask(new ListPreTripChecklists(
            vehicleId: $validated['vehicle_id'] ?? null,
            onlyPassed: $onlyPassed,
        ));

        return $this->respondCollection(array_map(
            static fn (PreTripChecklistView $view): array => $view->toArray(),
            $checklists,
        ));
    }

    /**
     * POST /fleet/pre-trip-checklists — registra un checklist pre-salida ejecutado.
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

        return $this->respondAccepted([
            'vehicle_id' => $validated['vehicle_id'],
            'driver_id' => $validated['driver_id'],
            'performed_on' => $validated['performed_on'],
        ]);
    }
}
