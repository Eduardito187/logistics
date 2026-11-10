<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\GetShipmentLabels;

use Logistics\Labels\Application\Contract\LabelEnricher;
use Logistics\Labels\Application\ReadModel\EmittedLabelView;
use Logistics\Labels\Domain\Contract\EmittedLabelRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista las etiquetas emitidas de una orden, ENRIQUECIDAS con datos humanos (producto, origen, destino)
 * para la app. Arma las vistas base desde el dominio y delega la resolución cross-context (nombres, cero
 * uuid) al {@see LabelEnricher}, que lee catálogo/envíos/red en solo-lectura y tolerante.
 *
 * @implements QueryHandler<GetShipmentLabels, list<EmittedLabelView>>
 */
final readonly class GetShipmentLabelsHandler implements QueryHandler
{
    public function __construct(
        private EmittedLabelRepository $repository,
        private LabelEnricher $enricher,
    ) {}

    /**
     * @return list<EmittedLabelView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof GetShipmentLabels);

        $views = array_map(
            EmittedLabelView::fromEmittedLabel(...),
            $this->repository->forOrder($query->orderNumber),
        );

        return $this->enricher->enrich($query->orderNumber, $views);
    }
}
