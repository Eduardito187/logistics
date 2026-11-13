<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

/**
 * Puerto hacia el contexto de envíos para enumerar los envíos SLOTEABLES: los que tienen una fecha de
 * entrega solicitada pero AÚN sin ventana asignada, en el horizonte despachable.
 *
 * Es la fuente del barrido de auto-slot (`logistics:promise:autoslot-sweep`), la red de seguridad que
 * slotea lo que un reactor pudo haberse perdido, y de la cola "Unslotted" de operaciones. El dominio
 * define QUÉ necesita (id de envío + fecha); CÓMO se lee —con la consulta pública de `logistics/shipments`—
 * lo resuelve Infrastructure. Así promise no importa su dominio: lo conoce solo por este puerto y por
 * primitivos.
 */
interface PendingSlotShipmentSource
{
    /**
     * Envíos con fecha solicitada y sin ventana, hasta `$limit` elementos. Cada ítem en primitivos: id del
     * envío y la fecha (Y-m-d). El orden lo decide el adaptador (por fecha ascendente es lo natural).
     *
     * @return iterable<array{shipment_id: string, date: string}>
     */
    public function pendingSlotShipments(int $limit): iterable;
}
