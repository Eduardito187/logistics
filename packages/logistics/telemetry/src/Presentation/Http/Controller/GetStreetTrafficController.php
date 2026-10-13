<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Query\GetStreetTraffic\GetStreetTraffic;
use Logistics\Telemetry\Application\ReadModel\StreetTrafficView;
use Logistics\Telemetry\Presentation\Http\ApiController;

/**
 * Devuelve el tráfico agregado de calles para pintar el mapa (consulta GetStreetTraffic).
 *
 * `bbox` (opcional) acota a la zona visible del mapa en el formato `min_lat,min_lng,max_lat,max_lng`;
 * si falta o es inválido, devuelve todo el conjunto.
 */
final class GetStreetTrafficController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<StreetTrafficView> $segments */
            $segments = $this->queries->ask(new GetStreetTraffic(
                bbox: $this->parseBbox($request->query('bbox')),
            ));

            return $this->data(
                array_map(static fn (StreetTrafficView $view): array => $view->toArray(), $segments),
            );
        });
    }

    /**
     * Parsea `bbox` en el formato `min_lat,min_lng,max_lat,max_lng` a la caja que espera el query.
     * Un valor ausente o mal formado devuelve null (= todo el país).
     *
     * @return ?array{min_lat: float, min_lng: float, max_lat: float, max_lng: float}
     */
    private function parseBbox(mixed $bbox): ?array
    {
        if (!is_string($bbox) || $bbox === '') {
            return null;
        }

        $parts = explode(',', $bbox);
        if (count($parts) !== 4) {
            return null;
        }

        foreach ($parts as $part) {
            if (!is_numeric(trim($part))) {
                return null;
            }
        }

        return [
            'min_lat' => (float) trim($parts[0]),
            'min_lng' => (float) trim($parts[1]),
            'max_lat' => (float) trim($parts[2]),
            'max_lng' => (float) trim($parts[3]),
        ];
    }
}
