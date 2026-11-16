<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Http\Support\ApiPagination;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Application\Command\DisbandCrew\DisbandCrew;
use Logistics\Fleet\Application\Contract\CrewDirectory;
use Logistics\Fleet\Application\Query\ListCrews\ListCrews;
use Logistics\Fleet\Domain\Exception\CrewConflict;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Endpoints HTTP de tripulaciones (chofer + ayudantes + vehículo para una fecha).
 *
 * Regla del día: un chofer tiene un solo camión por día y un camión una sola tripulación por día.
 *
 * - index: lista las tripulaciones vigentes por fecha, vehículo o chofer (paginada).
 * - store: arma la tripulación del día; si el chofer ya tenía una ese día, la REEMPLAZA.
 * - update: cambia una tripulación (otro chofer, vehículo, ayudantes o fecha).
 * - destroy: quita la asignación (queda su rastro en el historial).
 *
 * @phpstan-import-type CrewRow from CrewDirectory
 *
 * @phpstan-type CrewInput array{driver_id: string, vehicle_id: string, assembled_for: string, helper_ids?: list<string>, shift_starts_at?: string|null, shift_ends_at?: string|null, replace?: bool}
 */
final readonly class CrewController
{
    use RespondsWithEnvelope;

    private const string OUTCOME_CREATED = 'created';

    private const string OUTCOME_REPLACED = 'replaced';

    private const string OUTCOME_UNCHANGED = 'unchanged';

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
     * PUT /fleet/crews/{crewId} — cambia la tripulación: la editada queda sin efecto y la nueva ocupa su
     * lugar, en una sola operación (si la nueva no se puede armar —vehículo no apto, turno inválido,
     * camión tomado— la anterior se conserva). Responde 200.
     */
    public function update(Request $request, string $crewId): JsonResponse
    {
        return $this->assemble($request, $crewId);
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
     * POST /fleet/crews — arma la tripulación de un chofer para una fecha.
     *
     * - 201 `outcome: created`: el chofer no tenía tripulación ese día.
     * - 200 `outcome: replaced`: ya tenía una; quedó sin efecto y esta la reemplaza (cambio de camión,
     *   de ayudantes o de turno). Sin `helper_ids` se conservan los ayudantes anteriores.
     * - 200 `outcome: unchanged`: ya tenía exactamente esto; no se tocó nada.
     * - 409 `conflict`: el camión ya lo tiene otro chofer ese día. Con `replace: true` se le quita.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->assemble($request, null);
    }

    /**
     * Arma (o reemplaza) y responde con la tripulación vigente del chofer y qué pasó con la anterior.
     */
    private function assemble(Request $request, ?string $editedCrewId): JsonResponse
    {
        $validated = $this->validateCrew($request);
        $before = $this->currentCrew($validated['driver_id'], $validated['assembled_for']);

        try {
            $this->commandBus->dispatch(new AssembleCrew(
                driverId: $validated['driver_id'],
                // Sin la clave, el pedido no opina sobre ayudantes: se conservan los de la anterior.
                helperIds: array_key_exists('helper_ids', $validated) ? array_values($validated['helper_ids']) : null,
                vehicleId: $validated['vehicle_id'],
                assembledFor: $validated['assembled_for'],
                shiftStartsAt: $validated['shift_starts_at'] ?? null,
                shiftEndsAt: $validated['shift_ends_at'] ?? null,
                replace: (bool) ($validated['replace'] ?? false),
                replacesCrewId: $editedCrewId,
            ));
        } catch (CrewConflict $e) {
            return $this->conflict($request, $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            // Dos pedidos simultáneos que esquivaron el bloqueo: el índice único frenó el duplicado.
            return $this->conflict($request, CrewConflict::concurrentAssignment()->getMessage());
        }

        $after = $this->currentCrew($validated['driver_id'], $validated['assembled_for']);
        // Editar (PUT) siempre parte de una tripulación que ya existía: nunca es un alta.
        $outcome = $this->outcome($before ?? ($editedCrewId !== null ? ['id' => $editedCrewId] : null), $after);

        return $this->respondAccepted([
            'driver_id' => $validated['driver_id'],
            'vehicle_id' => $validated['vehicle_id'],
            'assembled_for' => $validated['assembled_for'],
            'crew_id' => $after['id'] ?? null,
            'outcome' => $outcome,
            'vehicle_plate' => $after['vehicle_plate'] ?? null,
            'previous_vehicle_plate' => $outcome === self::OUTCOME_REPLACED ? ($before['vehicle_plate'] ?? null) : null,
            'helpers' => $after['helpers'] ?? [],
        ], $outcome === self::OUTCOME_CREATED ? 201 : 200);
    }

    /**
     * Qué pasó con la tripulación del chofer: `created` (no tenía), `unchanged` (sigue la misma) o
     * `replaced` (otra ocupó su lugar).
     *
     * @param array{id: string, ...}|null $before
     * @param CrewRow|null                $after
     */
    private function outcome(?array $before, ?array $after): string
    {
        if ($before === null) {
            return self::OUTCOME_CREATED;
        }

        return ($after['id'] ?? null) === $before['id'] ? self::OUTCOME_UNCHANGED : self::OUTCOME_REPLACED;
    }

    /**
     * Tripulación vigente del chofer en la fecha (la más reciente si por datos viejos hubiera más), o null.
     *
     * @return CrewRow|null
     */
    private function currentCrew(string $driverId, string $date): ?array
    {
        /** @var array{items: list<CrewRow>, total: int} $page */
        $page = $this->queryBus->ask(new ListCrews(date: $date, driverId: $driverId, limit: 1, offset: 0));

        return $page['items'][0] ?? null;
    }

    private function conflict(Request $request, string $detail): JsonResponse
    {
        return ProblemDetails::fromCode(ErrorCode::Conflict, $detail)
            ->withInstance($request->getRequestUri())
            ->toResponse($request);
    }

    /**
     * @return CrewInput
     */
    private function validateCrew(Request $request): array
    {
        /** @var CrewInput */
        return $request->validate([
            'driver_id' => ['required', 'uuid'],
            'helper_ids' => ['sometimes', 'array'],
            'helper_ids.*' => ['uuid'],
            'vehicle_id' => ['required', 'uuid'],
            'assembled_for' => ['required', 'date_format:Y-m-d'],
            // Turno opcional: si viene uno, debe venir el otro (el handler valida reglas y descansos).
            'shift_starts_at' => ['sometimes', 'nullable', 'date', 'required_with:shift_ends_at'],
            'shift_ends_at' => ['sometimes', 'nullable', 'date', 'required_with:shift_starts_at'],
            // Confirma quitarle el camión al chofer que ya lo tiene ese día.
            'replace' => ['sometimes', 'boolean'],
        ]);
    }
}
