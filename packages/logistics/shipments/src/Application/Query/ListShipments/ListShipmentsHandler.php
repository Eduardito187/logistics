<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListShipments;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Shipments\Application\Contract\ShipmentListReader;
use Logistics\Shipments\Application\Contract\ShipmentProgressReader;
use Logistics\Shipments\Application\Query\ShipmentListSupport;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\OrderSource;

/**
 * Lista envíos aplicando los filtros opcionales por estado, fuente, nodo destino y rango de fecha
 * prometida, y adjunta a cada uno su AVANCE operativo (ShipmentProgressReader, en lote por página).
 *
 * Lado de lectura CQRS: delega en ShipmentListReader, que empuja los filtros a SQL y arma los
 * ShipmentView sin reconstruir el agregado ni cargar la tabla entera en memoria. Antes se recorría
 * ShipmentRepository::all() y se filtraba en PHP, inviable a escala de millones de filas.
 *
 * Si el query trae `limit` (lo setea el controller al paginar), baja LIMIT/OFFSET a SQL y devuelve una
 * Page (ítems de la página + total vía COUNT(*)). Sin `limit`, devuelve la lista completa (consumidores
 * que aún no paginan), preservando el comportamiento histórico.
 *
 * @implements QueryHandler<ListShipments, list<ShipmentView>|Page<ShipmentView>>
 */
final class ListShipmentsHandler implements QueryHandler
{
    public function __construct(
        private readonly ShipmentListReader $reader,
        private readonly ShipmentProgressReader $progress,
    ) {}

    /**
     * @return list<ShipmentView>|Page<ShipmentView>
     */
    public function handle(Query $query): array | Page
    {
        if (!$query instanceof ListShipments) {
            throw ShipmentsException::because('ListShipmentsHandler solo maneja ListShipments');
        }

        // Se normaliza/valida vía el enum (lanza ante un valor inválido, igual que antes) y se pasa el
        // valor string del enum al lector, que lo compara contra la columna persistida. El estado se acepta
        // en cualquier capitalización (planned/PLANNED).
        $status = $query->status !== null ? ShipmentListSupport::status($query->status) : null;
        $source = $query->source !== null ? OrderSource::from($query->source)->value : null;

        $views = $this->reader->list(
            status: $status,
            source: $source,
            destinationNodeId: $query->destinationNodeId,
            limit: $query->limit,
            offset: $query->offset,
            deliveryDateFrom: $query->deliveryDateFrom,
            deliveryDateTo: $query->deliveryDateTo,
        );

        $views = ShipmentListSupport::withProgress($views, $this->progress);

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->reader->count(
            status: $status,
            source: $source,
            destinationNodeId: $query->destinationNodeId,
            deliveryDateFrom: $query->deliveryDateFrom,
            deliveryDateTo: $query->deliveryDateTo,
        ));
    }
}
