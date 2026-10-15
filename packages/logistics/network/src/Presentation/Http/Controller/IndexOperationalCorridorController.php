<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Network\Application\Query\OperationalCorridorsByDepartment\OperationalCorridorsByDepartmentQuery;
use Logistics\Network\Application\ReadModel\OperationalCorridorView;
use Logistics\Network\Presentation\Http\Request\IndexOperationalCorridorRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los corredores operativos de un departamento para la app interna (puerta app, solo lectura).
 *
 * Es el mismo insumo que planning/promise consumen por el QueryBus, expuesto también por HTTP. Llama
 * al QueryBus (nunca a Infrastructure ni al dominio).
 */
final class IndexOperationalCorridorController
{
    public function __invoke(IndexOperationalCorridorRequest $request, QueryBus $bus): JsonResponse
    {
        /** @var list<OperationalCorridorView> $corridors */
        $corridors = $bus->ask(new OperationalCorridorsByDepartmentQuery($request->department()));

        $data = array_map(
            static fn (OperationalCorridorView $corridor): array => $corridor->toArray(),
            $corridors,
        );

        return new JsonResponse([
            'data' => $data,
            'meta' => ['count' => count($data)],
        ]);
    }
}
