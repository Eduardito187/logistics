<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\GetShipmentLabels;

use Logistics\Labels\Application\ReadModel\EmittedLabelView;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve las etiquetas emitidas de un pedido como modelos de lectura.
 *
 * @implements QueryHandler<GetShipmentLabels, list<EmittedLabelView>>
 */
final readonly class GetShipmentLabelsHandler implements QueryHandler
{
    public function __construct(private EmittedLabelRepository $repository) {}

    /**
     * @return list<EmittedLabelView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof GetShipmentLabels);

        return array_map(
            EmittedLabelView::fromEmittedLabel(...),
            $this->repository->forOrder($query->orderNumber),
        );
    }
}
