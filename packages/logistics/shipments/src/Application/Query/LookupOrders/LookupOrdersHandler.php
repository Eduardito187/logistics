<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\LookupOrders;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\Contract\DeliveryModeReader;
use Logistics\Shipments\Application\Contract\OrderLookupReader;
use Logistics\Shipments\Application\Contract\ScannedLabelResolver;
use Logistics\Shipments\Application\Contract\ShipmentListReader;
use Logistics\Shipments\Application\Contract\ShipmentProgressReader;
use Logistics\Shipments\Application\Contract\ShipmentScopeReader;
use Logistics\Shipments\Application\Query\ShipmentListSupport;
use Logistics\Shipments\Application\ReadModel\OrderLookupResult;
use Logistics\Shipments\Application\ReadModel\OrderLookupView;
use Logistics\Shipments\Application\ReadModel\OrderRouteFacts;
use Logistics\Shipments\Application\ReadModel\OrderTrackingView;
use Logistics\Shipments\Application\ReadModel\ShipmentListFilter;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;
use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Resuelve la consulta abierta de pedidos. NO aplica alcance por rol: cualquier usuario autenticado de la
 * app encuentra cualquier pedido; el rol solo se usa para calcular `can_process`, con la MISMA regla de
 * alcance que filtra el listado y guarda las escrituras (ShipmentScopeReader): un preparador de otra ciudad,
 * una tienda ajena o un chofer sin ese pedido en su ruta lo ven con `can_process=false`.
 *
 * Orden de resolución del texto buscado (se detiene en el primero que encuentra algo):
 *  1. código de pedido EXACTO (código externo o referencia de orden; trae los envíos hermanos del pedido);
 *  2. identificador de una etiqueta escaneada → su pedido (contexto de etiquetas, por contrato);
 *  3. coincidencia parcial de texto: por código de pedido o referencia de orden para cualquiera; además por
 *     nombre o documento del cliente solo si la consulta lo habilita (`searchCustomerData`: oficina y tienda).
 *     Quien no lo tiene habilitado no recibe un rechazo: ese texto simplemente no coincide con datos del cliente.
 *
 * Después enriquece el puñado de envíos encontrados, en lote: avance operativo y modalidad (los mismos del
 * listado), hechos de ruta/seguimiento y nombre del nodo destino. Costo fijo, sin una consulta por envío.
 *
 * @implements QueryHandler<LookupOrders, OrderLookupResult>
 */
final class LookupOrdersHandler implements QueryHandler
{
    /** Largo mínimo para la coincidencia parcial (evita que un texto muy corto traiga cualquier pedido). */
    private const MIN_SEARCH_LENGTH = 3;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly OrderLookupReader $lookup,
        private readonly ScannedLabelResolver $labels,
        private readonly ShipmentListReader $shipments,
        private readonly ShipmentProgressReader $progress,
        private readonly DeliveryModeReader $deliveryModes,
        private readonly ShipmentScopeReader $scope,
    ) {}

    public function handle(Query $query): OrderLookupResult
    {
        if (!$query instanceof LookupOrders) {
            throw ShipmentsException::because('LookupOrdersHandler solo maneja LookupOrders');
        }

        $term = trim($query->term);
        if ($term === '') {
            return new OrderLookupResult([], OrderLookupResult::MATCHED_BY_CODE);
        }

        // 1) Código de pedido exacto.
        $ids = $this->lookup->shipmentIdsByCode($term, LookupOrders::MAX_RESULTS);
        if ($ids !== []) {
            return $this->result($this->byIds($ids), OrderLookupResult::MATCHED_BY_CODE, null, $query->actor);
        }

        // 2) Identificador de una etiqueta escaneada.
        $label = $this->labels->resolve($term);
        if ($label !== null) {
            $labelShipmentId = self::uuidOrNull($label['shipment_id']);
            $ids = $label['order_number'] !== null
                ? $this->lookup->shipmentIdsByCode($label['order_number'], LookupOrders::MAX_RESULTS)
                : [];
            if ($ids === [] && $labelShipmentId !== null) {
                $ids = [$labelShipmentId];
            }

            $views = $this->byIds($ids);
            if ($views !== []) {
                return $this->result($views, OrderLookupResult::MATCHED_BY_LABEL, $labelShipmentId, $query->actor);
            }
        }

        // 3) Coincidencia parcial de texto, acotada al tope. Los datos del cliente solo entran si se habilitó.
        if (mb_strlen($term) < self::MIN_SEARCH_LENGTH) {
            return new OrderLookupResult([], OrderLookupResult::MATCHED_BY_SEARCH);
        }

        $views = $this->shipments->list(
            new ShipmentListFilter(search: $term, searchCustomerData: $query->searchCustomerData),
            LookupOrders::MAX_RESULTS,
        );

        return $this->result($views, OrderLookupResult::MATCHED_BY_SEARCH, null, $query->actor);
    }

    /**
     * Vistas de un conjunto cerrado de envíos (ids ya validados), en el orden estable del listado.
     *
     * @param  list<string>       $ids
     * @return list<ShipmentView>
     */
    private function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->shipments->list(new ShipmentListFilter(shipmentIds: $ids), LookupOrders::MAX_RESULTS);
    }

    /**
     * Enriquece los envíos encontrados y arma el resultado.
     *
     * @param list<ShipmentView> $views
     */
    private function result(array $views, string $matchedBy, ?string $matchedShipmentId, ?ShipmentScopeActor $actor): OrderLookupResult
    {
        if ($views === []) {
            return new OrderLookupResult([], $matchedBy);
        }

        $views = ShipmentListSupport::withDeliveryMode(ShipmentListSupport::withProgress($views, $this->progress), $this->deliveryModes);
        $views = ShipmentListSupport::withOrigin($views, $this->scope);

        $ids = array_map(static fn (ShipmentView $view): string => $view->id, $views);
        $facts = $this->lookup->routeFacts($ids);
        $nodeNames = $this->lookup->nodeNames(self::destinationNodeIds($views));
        // Envíos que quien consulta puede operar (una sola sentencia para el lote, regla única de alcance).
        $operable = $actor === null ? null : array_fill_keys($this->scope->inScope($actor, $ids), true);

        $items = [];
        foreach ($views as $view) {
            $viewFacts = $facts[$view->id] ?? new OrderRouteFacts;
            $nodeName = $view->destinationNodeId !== null ? ($nodeNames[$view->destinationNodeId] ?? null) : null;

            $items[] = new OrderLookupView(
                shipment: $view,
                orderReference: $viewFacts->orderReference,
                destinationNodeName: $nodeName,
                tracking: OrderTrackingView::of($view, $viewFacts, $nodeName),
                canProcess: $operable === null || isset($operable[$view->id]),
            );
        }

        $matched = $matchedShipmentId !== null && in_array($matchedShipmentId, $ids, true) ? $matchedShipmentId : null;

        return new OrderLookupResult($items, $matchedBy, $matched);
    }

    /**
     * @param  list<ShipmentView> $views
     * @return list<string>
     */
    private static function destinationNodeIds(array $views): array
    {
        $nodeIds = [];
        foreach ($views as $view) {
            if ($view->destinationNodeId !== null && $view->destinationNodeId !== '') {
                $nodeIds[$view->destinationNodeId] = true;
            }
        }

        return array_keys($nodeIds);
    }

    /**
     * El valor solo si tiene forma de UUID (comparar otra cosa contra una columna uuid falla en PostgreSQL).
     */
    private static function uuidOrNull(?string $value): ?string
    {
        return $value !== null && preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }
}
