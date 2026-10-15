<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Coverage\Application\Command\ImportCoverageKml;
use Logistics\Coverage\Presentation\Http\ApiResponse;
use Logistics\Coverage\Presentation\Http\Request\ImportCoverageKmlRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Importa el mapa de cobertura vigente desde un documento KML (Google Earth / Maps).
 *
 * Es una escritura de la puerta /integrations (credenciales de servicio): un proceso externo empuja
 * el KML completo. El comando lo procesa el handler del contexto a través del bus transaccional.
 */
final class ImportCoverageKmlController
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function __invoke(ImportCoverageKmlRequest $request): JsonResponse
    {
        $this->commandBus->dispatch(new ImportCoverageKml($request->kml()));

        return ApiResponse::accepted(['status' => 'import_accepted']);
    }
}
