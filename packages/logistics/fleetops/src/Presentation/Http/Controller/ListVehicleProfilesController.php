<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\ListVehicleProfiles\ListVehicleProfiles;
use Logistics\FleetOps\Application\ReadModel\VehicleProfileView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista todos los perfiles técnicos de vehículos de la flota propia (consulta ListVehicleProfiles).
 */
final class ListVehicleProfilesController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(): JsonResponse
    {
        return $this->guard(function (): JsonResponse {
            /** @var list<VehicleProfileView> $profiles */
            $profiles = $this->queries->ask(new ListVehicleProfiles);

            return $this->data(
                array_map(static fn (VehicleProfileView $view): array => $view->toArray(), $profiles),
            );
        });
    }
}
