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
 * El vínculo usuario↔tripulante sale de `users.driver_id` (chofer) o `users.helper_id` (ayudante/rol
 * tripulante), referencias por valor a los UUID de fleet_drivers / fleet_helpers (ver las migraciones
 * add_driver_id_to_users_table y add_helper_id_to_users_table). Si la cuenta autenticada no está ligada
 * a un chofer ni a un ayudante, o no tiene asignación para la fecha, se responde 404 en formato RFC 7807.
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
        $driverId = $user instanceof User ? $this->linkId($user->driver_id) : null;
        $helperId = $user instanceof User ? $this->linkId($user->helper_id) : null;

        if ($driverId === null && $helperId === null) {
            return ProblemDetails::fromCode(
                ErrorCode::NotFound,
                'La cuenta autenticada no está vinculada a un chofer ni a un ayudante.',
            )->withInstance($request->getRequestUri())->toResponse($request);
        }

        // Sin `?date` explícito se pide la asignación VIGENTE: una entrega EN CURSO que cruzó la medianoche no
        // se le quita al chofer (ver GetMyCrewHandler). Con una fecha explícita (p. ej. previsualizar un día
        // futuro) se respeta esa fecha exacta, sin arrastre.
        /** @var MyCrewView|null $crew */
        $crew = $this->queryBus->ask(new GetMyCrew($driverId, $date, $helperId, allowOpenCarryover: !$request->filled('date')));

        if ($crew === null) {
            return ProblemDetails::fromCode(
                ErrorCode::NotFound,
                "No hay asignación de tripulación para la cuenta en la fecha {$date}.",
            )->withInstance($request->getRequestUri())->toResponse($request);
        }

        return $this->respondData($crew->toArray());
    }

    /**
     * Normaliza un vínculo de la cuenta (driver_id / helper_id): devuelve el string no vacío o null.
     */
    private function linkId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
