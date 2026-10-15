<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

/**
 * Puerto hacia el contexto de envíos para leer y fijar la franja de entrega de un pedido.
 *
 * El dominio de promise define QUÉ necesita —saber si el pedido ya tiene franja (para ser idempotente)
 * y fijarle la elegida—; CÓMO se persiste en el envío lo resuelve `logistics/shipments` detrás de este
 * contrato. Así promise no importa el dominio de shipments: lo conoce solo por este puerto y por
 * primitivos (orderId string, fecha y ventana como texto).
 */
interface OrderDeliverySlotGateway
{
    /**
     * Franja de entrega vigente del pedido, o null si todavía no tiene una (o el pedido no existe).
     *
     * @return array{date: string, window_start: string, window_end: string}|null
     */
    public function currentSlot(string $orderId): ?array;

    /**
     * Fija (o reemplaza) la franja de entrega del pedido. Idempotente aguas abajo: fijar la misma franja
     * no produce un cambio nuevo.
     */
    public function assignSlot(string $orderId, string $date, string $windowStart, string $windowEnd): void;
}
