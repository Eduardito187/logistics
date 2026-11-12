<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Fleet\Application\Query\GetMyCrew\GetMyCrew;
use Logistics\Fleet\Application\ReadModel\MyCrewView;
use Logistics\Fleet\Presentation\Http\Concerns\NormalizesDateParameter;
use Logistics\Fleet\Presentation\Http\Concerns\RespondsWithEnvelope;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lectura de la asignación del día del chofer AUTENTICADO (puerta app interna).
 *
 * El chofer no elige vehículo ni tripulación: se los arma el admin con AssembleCrew. Este endpoint deja
 * que el chofer consulte SU asignación (vehículo + chofer + ayudantes) para una fecha.
 *
 * El vínculo usuario↔chofer sale de `users.driver_id` (referencia por valor al UUID de fleet_drivers;
 * ver la migración add_driver_id_to_users_table). Si la cuenta autenticada no está ligada a un chofer,
 * o no tiene asignación para la fecha, se responde 404 en formato RFC 7807.
 */
final readonly class MyCrewController
{
    use NormalizesDateParameter;
    use RespondsWithEnvelope;

    public function __construct(
        private QueryBus $queryBus,
    ) {}

    /**
     * GET /fleet/my-crew — asignación del día (vehículo + tripulación) del chofer autenticado.
     */
    public function show(Request $request): JsonResponse
    {
        // Fecha de armado a consultar (#29): acepta YYYY-MM-DD o fecha-hora ISO 8601 y la normaliza a fecha
        // de calendario; por defecto hoy en America/La_Paz. Inválida → 422.
        $date = $this->operationalDate($request);

        $user = $request->user();
        $driverId = $user instanceof User ? $user->driver_id : null;

        if (!is_string($driverId) || $driverId === '') {
            return ProblemDetails::fromCode(
                ErrorCode::NotFound,
                'La cuenta autenticada no está vinculada a un chofer.',
            )->withInstance($request->getRequestUri())->toResponse($request);
        }

        /** @var MyCrewView|null $crew */
        $crew = $this->queryBus->ask(new GetMyCrew($driverId, $date));

        if ($crew === null) {
            return ProblemDetails::fromCode(
                ErrorCode::NotFound,
                "No hay asignación de tripulación para el chofer en la fecha {$date}.",
            )->withInstance($request->getRequestUri())->toResponse($request);
        }

        return $this->respondData($crew->toArray());
    }
}
