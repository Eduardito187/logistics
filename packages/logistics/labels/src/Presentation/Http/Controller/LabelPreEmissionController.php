<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Logistics\Labels\Application\Command\PreEmitOrderLabels\PreEmitOrderLabels;
use Logistics\Labels\Application\Query\GetShipmentLabels\GetShipmentLabels;
use Logistics\Labels\Application\ReadModel\EmittedLabelView;
use Logistics\Labels\Presentation\Http\Request\PreEmitOrderLabelsRequest;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Pre-emisión de etiquetas al planificar (puerta /api/v1/integrations/*).
 *
 * Lo invoca el worker/integrador cuando se publica un plan (planning.plan_published): deja las
 * etiquetas firmadas y renderizadas listas antes de preparar el pedido. Es idempotente, por eso
 * responde 200 (no 201): reentregar el evento no crea etiquetas nuevas. Solo habla con los buses CQRS.
 */
final readonly class LabelPreEmissionController
{
    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    public function __invoke(PreEmitOrderLabelsRequest $request, string $orderNumber): JsonResponse
    {
        $this->commandBus->dispatch(new PreEmitOrderLabels(
            orderNumber: $orderNumber,
            origin: $request->origin(),
            destination: $request->destination(),
            lines: $request->orderLines(),
        ));

        /** @var list<EmittedLabelView> $views */
        $views = $this->queryBus->ask(new GetShipmentLabels($orderNumber));

        $data = array_map(static fn (EmittedLabelView $view): array => $view->toArray(), $views);

        return new JsonResponse(['data' => $data], Response::HTTP_OK);
    }
}
