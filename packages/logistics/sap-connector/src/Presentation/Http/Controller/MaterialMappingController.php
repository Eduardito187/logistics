<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\SapConnector\Application\Command\DefineMaterialMapping\DefineMaterialMapping;
use Logistics\SapConnector\Application\Query\ListMaterialMappings\ListMaterialMappings;
use Logistics\SapConnector\Application\ReadModel\MaterialMappingView;
use Logistics\SapConnector\Presentation\Http\Request\DefineMaterialMappingRequest;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta APP: administración del mapa de traducción sku propio ↔ coordenada SAP (capa anti-corrupción,
 * ADR 002, P2-SAP-06). Lee por el QueryBus y define/actualiza por el CommandBus; nunca toca SAP.
 */
final class MaterialMappingController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly CommandBus $commandBus,
    ) {}

    /**
     * GET: mapa de traducción completo.
     */
    public function index(): JsonResponse
    {
        /** @var list<MaterialMappingView> $mappings */
        $mappings = $this->queryBus->ask(new ListMaterialMappings);

        $data = array_map(
            static fn (MaterialMappingView $view): array => $view->toArray(),
            $mappings,
        );

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'count' => count($data),
            ],
        ]);
    }

    /**
     * POST: define o actualiza (idempotente por sku) un mapeo de material.
     */
    public function store(DefineMaterialMappingRequest $request): JsonResponse
    {
        /** @var array{sku: string, material: string, center: string, warehouse: string} $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new DefineMaterialMapping(
            $data['sku'],
            $data['material'],
            $data['center'],
            $data['warehouse'],
        ));

        return new JsonResponse([
            'data' => [
                'sku' => $data['sku'],
                'material' => $data['material'],
                'center' => $data['center'],
                'warehouse' => $data['warehouse'],
            ],
            'meta' => [
                'message' => 'Material mapping defined.',
            ],
        ], JsonResponse::HTTP_CREATED);
    }
}
