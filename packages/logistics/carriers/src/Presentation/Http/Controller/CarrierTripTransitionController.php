<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Logistics\Carriers\Application\Query\GetCarrierTrip\GetCarrierTrip;
use Logistics\Carriers\Application\ReadModel\CarrierTripView;
use Logistics\Carriers\Domain\Exception\CarrierTripNotFound;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Base de las transiciones de un viaje desde la app (iniciar / cerrar).
 *
 * Despacha el comando (idempotente en el dominio) y responde 200 con el ESTADO resultante del viaje
 * (`started_at` / `closed_at` / `status`), tanto la primera vez como en los reintentos. Errores en
 * RFC 7807 como el resto de la puerta app: viaje inexistente o id inválido → 404; transición inválida
 * (p. ej. cerrar uno programado, iniciar uno cerrado o sin carga) → 422 `business_rule_violation`.
 * La autorización por dueño (oficina o el transportista del viaje) la hace EnsureCarrierScope (modo trip).
 */
abstract class CarrierTripTransitionController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    /**
     * Comando de la transición concreta para el viaje y el actor dados.
     */
    abstract protected function command(string $tripId, ?string $actorId): Command;

    public function __invoke(Request $request, string $tripId): JsonResponse
    {
        try {
            $this->commands->dispatch($this->command($tripId, $this->actorId($request)));

            $trip = $this->queries->ask(new GetCarrierTrip(tripId: $tripId));
            assert($trip instanceof CarrierTripView);

            return new JsonResponse(['data' => $trip->toArray()]);
        } catch (CarrierTripNotFound | InvalidArgumentException) {
            return ProblemDetails::fromCode(ErrorCode::NotFound, 'Trip not found.')->toResponse($request);
        } catch (DomainException $e) {
            return ProblemDetails::fromCode(ErrorCode::BusinessRuleViolation, $e->getMessage())->toResponse($request);
        }
    }

    /**
     * Id del usuario autenticado como texto (auditoría started_by / closed_by), o null.
     */
    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return \is_int($id) || \is_string($id) ? (string) $id : null;
    }
}
