<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Coverage\Application\Query\ListZones;
use Logistics\Coverage\Application\ReadModel\ZoneView;
use Logistics\Coverage\Presentation\Http\ApiResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista todas las zonas de cobertura con su polígono y reglas.
 *
 * Alimenta el visor de zonas básico (GeoJSON por zona) y permite a las integraciones sincronizar
 * el mapa de cobertura vigente.
 */
final class ListZonesController
{
    public function __construct(private readonly QueryBus $queryBus) {}

    public function __invoke(): JsonResponse
    {
        /** @var array<int, ZoneView> $views */
        $views = $this->queryBus->ask(new ListZones);

        $data = array_map(
            static fn (ZoneView $view): array => $view->toArray(),
            $views,
        );

        return ApiResponse::ok($data);
    }
}
