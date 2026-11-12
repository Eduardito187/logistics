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

        return $this->enricher->enrich($query->orderNumber, $this->latestPerPhysicalUnit($views));
    }

    /**
     * Deduplica por identidad física del bulto `(sku, unidad, pieza)` quedándose con la emisión MÁS
     * RECIENTE. Las etiquetas son permanentes/append-only: una re-emisión (p. ej. rotación de clave o
     * reimpresión) agrega filas nuevas para el MISMO bulto sin borrar las viejas, así que el crudo trae
     * duplicados. La app/impresora debe ver una etiqueta vigente por bulto, no el historial. No confunde
     * cantidad > 1 (unidades legítimas distintas tienen otro `unitIndex`/`pieceIndex`) ni depende de
     * `lineNumber` (la re-emisión puede swapear líneas del mismo bulto).
     *
     * @param list<EmittedLabelView> $views
     * @return list<EmittedLabelView>
     */
    private function latestPerPhysicalUnit(array $views): array
    {
        $latest = [];
        foreach ($views as $view) {
            $key = $view->sku.'|'.$view->unitIndex.'|'.$view->pieceIndex;
            if (!isset($latest[$key]) || $view->emittedAt >= $latest[$key]->emittedAt) {
                $latest[$key] = $view;
            }
        }

        return array_values($latest);
    }
}
