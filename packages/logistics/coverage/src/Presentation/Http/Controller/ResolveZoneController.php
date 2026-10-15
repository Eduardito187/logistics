<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Coverage\Application\Query\ResolveZoneForPoint;
use Logistics\Coverage\Application\ReadModel\ZoneView;
use Logistics\Coverage\Presentation\Http\ApiResponse;
use Logistics\Coverage\Presentation\Http\Request\ResolveZoneRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Resuelve en qué zona de cobertura cae un punto (lat, lng) y devuelve su nodo y reglas.
 *
 * La app interna (Flutter) la usa para saber si una dirección es atendible y con qué servicios;
 * las integraciones la usan para validar direcciones antes de prometer una entrega.
 */
final class ResolveZoneController
{
    public function __construct(private readonly QueryBus $queryBus) {}

    public function __invoke(ResolveZoneRequest $request): JsonResponse
    {
        $view = $this->queryBus->ask(new ResolveZoneForPoint($request->lat(), $request->lng()));

        if (!$view instanceof ZoneView) {
            return ApiResponse::error(
                'zone_not_found',
                'No coverage zone contains the given point.',
                404,
            );
        }

        return ApiResponse::ok($view->toArray());
    }
}
