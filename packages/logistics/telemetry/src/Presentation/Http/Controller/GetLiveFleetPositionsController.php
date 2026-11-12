<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;
use Logistics\Telemetry\Presentation\Http\ApiController;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Devuelve la posición en vivo de la flota (última lectura conocida de cada vehículo) para pintar el mapa
 * (consulta GetLiveFleetPositions), con placa y chofer actual por vehículo (auditoría #17).
 *
 * Polling eficiente (aditivo, ambos opcionales):
 *  - `ETag` + `If-None-Match`: si nada cambió desde la respuesta anterior → 304 sin cuerpo.
 *  - `?since=<ISO 8601>`: solo los vehículos cuya posición cambió DESPUÉS de ese instante (hora del servidor;
 *    usar el `meta.server_time` de la respuesta anterior para no depender del reloj del teléfono).
 *
 * El ETag se calcula sobre `data` (no sobre `meta.server_time`, que cambia en cada respuesta).
 */
final class GetLiveFleetPositionsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): SymfonyResponse
    {
        $since = null;
        $rawSince = $request->query('since');
        if (is_string($rawSince) && trim($rawSince) !== '') {
            try {
                // Un '+' sin codificar en la query llega como espacio ("…T10:00:00 00:00"): se restituye.
                $since = new DateTimeImmutable(str_replace(' ', '+', trim($rawSince)));
            } catch (Throwable) {
                return $this->error('invalid_since', 'The since parameter must be an ISO 8601 date-time.', 422);
            }
        }

        return $this->guard(function () use ($request, $since): JsonResponse {
            $serverTime = Date::now()->format(DATE_ATOM);

            /** @var list<VehicleLiveView> $positions */
            $positions = $this->queries->ask(new GetLiveFleetPositions($since));
            $data = array_map(static fn (VehicleLiveView $view): array => $view->toArray(), $positions);

            $etag = 'W/"'.hash('sha256', ($since?->format(DATE_ATOM) ?? '').'|'.(string) json_encode($data)).'"';

            if ($this->matches($request, $etag)) {
                $notModified = new JsonResponse(null, Response::HTTP_NOT_MODIFIED);
                $notModified->setContent('');
                $notModified->headers->set('ETag', $etag);

                return $notModified;
            }

            $response = $this->data($data, 200, [
                'count' => count($data),
                'since' => $since?->format(DATE_ATOM),
                'server_time' => $serverTime,
            ]);
            $response->headers->set('ETag', $etag);
            $response->headers->set('Cache-Control', 'private, no-cache');

            return $response;
        });
    }

    /** ¿Algún ETag de `If-None-Match` coincide con el actual (o es `*`)? */
    private function matches(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-None-Match');
        if ($header === null || trim($header) === '') {
            return false;
        }

        $bare = static fn (string $tag): string => trim(preg_replace('/^W\//', '', trim($tag)) ?? '');
        foreach (explode(',', $header) as $candidate) {
            if (trim($candidate) === '*' || $bare($candidate) === $bare($etag)) {
                return true;
            }
        }

        return false;
    }
}
