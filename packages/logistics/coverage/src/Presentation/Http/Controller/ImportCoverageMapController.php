<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Coverage\Application\Command\ImportCoverageMap;
use Logistics\Coverage\Presentation\Http\ApiResponse;
use Logistics\Coverage\Presentation\Http\Request\ImportCoverageMapRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Importa el mapa de cobertura vigente desde un FeatureCollection de GeoJSON.
 *
 * Es una escritura de la puerta /integrations (credenciales de servicio): un proceso externo empuja
 * el mapa completo. El comando lo procesa el handler del contexto a través del bus transaccional.
 */
final class ImportCoverageMapController
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function __invoke(ImportCoverageMapRequest $request): JsonResponse
    {
        $this->commandBus->dispatch(new ImportCoverageMap($request->featureCollection()));

        return ApiResponse::accepted(['status' => 'import_accepted']);
    }
}
