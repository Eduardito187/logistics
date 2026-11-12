<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Command\ClearVehicleFitness\ClearVehicleFitness;
use Logistics\Fleet\Application\Query\GetVehicleFitness\GetVehicleFitness;
use Logistics\Fleet\Application\ReadModel\VehicleFitnessView;
use Logistics\Fleet\Presentation\Http\Concerns\NormalizesDateParameter;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Aptitud para despachar de un vehículo (auditoría #27). Habla solo con los buses CQRS.
 *
 * - show:  aptitud del vehículo en una fecha (por defecto hoy en America/La_Paz).
 * - clear: un supervisor habilita el vehículo tras la reparación (nota obligatoria, queda auditado).
 */
final readonly class VehicleFitnessController
{
    use NormalizesDateParameter;
    use RespondsWithEnvelope;

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    /**
     * GET /fleet/vehicles/{vehicleId}/fitness?date= — aptitud en la fecha (404 si el vehículo no existe).
     */
    public function show(Request $request, string $vehicleId): JsonResponse
    {
        $date = $this->operationalDate($request);

        /** @var VehicleFitnessView|null $fitness */
        $fitness = $this->queryBus->ask(new GetVehicleFitness($vehicleId, $date));

        if ($fitness === null) {
            return ProblemDetails::fromCode(
                ErrorCode::NotFound,
                "No existe el vehículo {$vehicleId}.",
            )->withInstance($request->getRequestUri())->toResponse($request);
        }

        return $this->respondData($fitness->toArray());
    }

    /**
     * POST /fleet/vehicles/{vehicleId}/clear-fitness — habilitar un vehículo no apto (supervisor/admin).
     *
     * 422 si el vehículo ya estaba apto o falta la nota de reparación (regla de negocio).
     */
    public function clear(Request $request, string $vehicleId): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        [$actorId, $actorLabel] = $this->actor($request);

        $this->commandBus->dispatch(new ClearVehicleFitness(
            vehicleId: $vehicleId,
            note: $validated['note'],
            clearedById: $actorId,
            clearedByLabel: $actorLabel,
        ));

        /** @var VehicleFitnessView|null $fitness */
        $fitness = $this->queryBus->ask(new GetVehicleFitness($vehicleId, $this->operationalDate($request)));

        return $this->respondData([
            'status' => 'ok',
            'vehicle_id' => $vehicleId,
            'fitness' => $fitness?->toArray(),
        ]);
    }

    /**
     * Autor de la habilitación desde la sesión: id como texto (la cuenta tiene id bigint) y etiqueta
     * legible (nombre o email). Sin sesión, se atribuye al sistema.
     *
     * @return array{0: string|null, 1: string}
     */
    private function actor(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            return [null, 'System'];
        }

        $id = $user->getAuthIdentifier();
        $actorId = is_scalar($id) ? (string) $id : null;
        $label = $actorId ?? 'System';

        if ($user instanceof Model) {
            $name = $user->getAttribute('name');
            $email = $user->getAttribute('email');
            if (is_string($name) && trim($name) !== '') {
                $label = $name;
            } elseif (is_string($email) && trim($email) !== '') {
                $label = $email;
            }
        }

        return [$actorId, $label];
    }
}
