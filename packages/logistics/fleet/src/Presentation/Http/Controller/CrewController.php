<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Application\Command\DisbandCrew\DisbandCrew;
use Logistics\Fleet\Application\Query\ListCrews\ListCrews;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Endpoints HTTP de tripulaciones (chofer + ayudantes + vehículo para una fecha).
 *
 * - index: lista las tripulaciones por fecha, vehículo o chofer (paginada).
 * - store: arma la tripulación del día desde la app interna (supervisor de patio).
 * - update: cambia una tripulación (otro chofer, vehículo, ayudantes o fecha).
 * - destroy: quita la asignación.
 */
final readonly class CrewController
{
    use RespondsWithEnvelope;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    /**
     * GET /fleet/crews — tripulaciones por fecha / vehículo / chofer, con placa y nombres ya resueltos.
     * Es la forma de ver «qué camión tiene cada chofer». Paginación estándar (page / per_page / cursor).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'vehicle_id' => ['sometimes', 'nullable', 'uuid'],
            'driver_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        /** @var array{items: list<array<string, mixed>>, total: int} $page */
        $page = $this->queryBus->ask(new ListCrews(
            date: $validated['date'] ?? null,
            vehicleId: $validated['vehicle_id'] ?? null,
            driverId: $validated['driver_id'] ?? null,
            limit: ApiPagination::limit($request),
            offset: ApiPagination::offset($request),
        ));

        return new JsonResponse(ApiPagination::envelope($page['items'], $page['total'], $request));
    }

    /**
     * PUT /fleet/crews/{crewId} — cambia la tripulación: quita la anterior y arma la nueva en una sola
     * operación (si la nueva no se puede armar —vehículo no apto, turno inválido— la anterior se conserva).
     */
    public function update(Request $request, string $crewId): JsonResponse
    {
        $validated = $this->validateCrew($request);

        DB::transaction(function () use ($crewId, $validated): void {
            $this->commandBus->dispatch(new DisbandCrew($crewId));
            $this->commandBus->dispatch($this->assembleCommand($validated));
        });

        return $this->respondAccepted([
            'driver_id' => $validated['driver_id'],
            'vehicle_id' => $validated['vehicle_id'],
            'assembled_for' => $validated['assembled_for'],
        ], 200);
    }

    /**
     * DELETE /fleet/crews/{crewId} — quita la asignación.
     */
    public function destroy(string $crewId): JsonResponse
    {
        $this->commandBus->dispatch(new DisbandCrew($crewId));

        return $this->respondAccepted(['crew_id' => $crewId], 200);
    }

    /**
     * POST /fleet/crews — arma una tripulación para una fecha.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateCrew($request);

        $this->commandBus->dispatch($this->assembleCommand($validated));

        return $this->respondAccepted([
            'driver_id' => $validated['driver_id'],
            'vehicle_id' => $validated['vehicle_id'],
            'assembled_for' => $validated['assembled_for'],
        ]);
    }

    /**
     * @return array{driver_id: string, vehicle_id: string, assembled_for: string, helper_ids?: list<string>, shift_starts_at?: string|null, shift_ends_at?: string|null}
     */
    private function validateCrew(Request $request): array
    {
        /** @var array{driver_id: string, vehicle_id: string, assembled_for: string, helper_ids?: list<string>, shift_starts_at?: string|null, shift_ends_at?: string|null} */
        return $request->validate([
            'driver_id' => ['required', 'uuid'],
            'helper_ids' => ['sometimes', 'array'],
            'helper_ids.*' => ['uuid'],
            'vehicle_id' => ['required', 'uuid'],
            'assembled_for' => ['required', 'date_format:Y-m-d'],
            // Turno opcional: si viene uno, debe venir el otro (el handler valida reglas y descansos).
            'shift_starts_at' => ['sometimes', 'nullable', 'date', 'required_with:shift_ends_at'],
            'shift_ends_at' => ['sometimes', 'nullable', 'date', 'required_with:shift_starts_at'],
        ]);
    }

    /**
     * @param array{driver_id: string, vehicle_id: string, assembled_for: string, helper_ids?: list<string>, shift_starts_at?: string|null, shift_ends_at?: string|null} $validated
     */
    private function assembleCommand(array $validated): AssembleCrew
    {
        return new AssembleCrew(
            driverId: $validated['driver_id'],
            helperIds: array_values($validated['helper_ids'] ?? []),
            vehicleId: $validated['vehicle_id'],
            assembledFor: $validated['assembled_for'],
            shiftStartsAt: $validated['shift_starts_at'] ?? null,
            shiftEndsAt: $validated['shift_ends_at'] ?? null,
        );
    }
}
