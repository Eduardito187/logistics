<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Contract;

use DateTimeImmutable;
use Logistics\Tracking\Application\ReadModel\DeliveredAwaitingRatingView;

/**
 * Puerto del read model de invitaciones a calificar sobre `tracking_shipments`: guarda el email del cliente
 * al proyectar la entrega, lista los candidatos y marca los ya invitados. No forma parte del agregado
 * ShipmentTracking (es dato operativo del proceso de invitación, no del estado visible).
 */
interface RatingRequestStore
{
    /**
     * Anota el email del cliente en el seguimiento del envío. Idempotente: solo completa si estaba vacío
     * (un reenvío del outbox no pisa el valor capturado en la primera entrega).
     */
    public function attachCustomerEmail(string $shipmentId, string $email): void;

    /**
     * Envíos entregados entre `since` y `deliveredBefore` (inclusive), con email, sin invitación previa y
     * sin respuesta de encuesta registrada. Ordenados por entrega ascendente (los más viejos primero).
     *
     * @return list<DeliveredAwaitingRatingView>
     */
    public function awaitingRating(DateTimeImmutable $since, DateTimeImmutable $deliveredBefore, int $limit): array;

    /**
     * Marca el envío como invitado. Condicional (`rating_requested_at IS NULL`): es el "claim" que evita
     * dobles invitaciones si dos workers procesan el mismo lote. Devuelve true solo si esta llamada lo marcó.
     */
    public function markRequested(string $shipmentId, DateTimeImmutable $requestedAt): bool;
}
