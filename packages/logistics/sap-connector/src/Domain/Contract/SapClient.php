<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\Contract;

use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\SapResponse;

/**
 * Puerto ÚNICO que habla con SAP. Es la frontera de la capa anti-corrupción (ADR 002): ningún otro
 * contrato ni contexto conoce SAP. El dominio lo define; Infrastructure lo implementa (hoy un stub
 * Null; mañana un cliente real, siempre detrás del outbox, nunca HTTP síncrono en un request).
 */
interface SapClient
{
    /**
     * Empuja un traspaso de stock a SAP. Lo invoca el worker de despacho, no un request de usuario.
     * Lanza en caso de error de comunicación para que el traspaso quede Failed y se reintente.
     */
    public function pushStockTransfer(StockTransfer $transfer): void;

    /**
     * Lee pedidos pendientes desde SAP como filas crudas (la forma es de SAP). El conector las traduce
     * luego a su DTO propio. Devuelve lista vacía si no hay pedidos.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchOrders(): array;

    /**
     * Avisa a SAP (fire-and-forget) un hecho de despacho. Lo invoca SOLO el job de la cola `sap`.
     *
     * Devuelve la respuesta cruda de SAP, sea 2xx o no (el llamador decide si cuenta como entregado y la
     * guarda). Lanza SapConnectorException solo ante fallo de comunicación (red, timeout, breaker abierto).
     */
    public function notifyEvent(SapNotification $notification): SapResponse;
}
