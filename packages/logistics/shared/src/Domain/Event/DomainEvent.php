<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Evento de dominio inmutable. Cada hecho relevante (un nodo creado, un bulto escaneado) es uno.
 *
 * De este flujo se alimentan tracking, auditoría, outbox e inteligencia (principio 3 de la
 * arquitectura). Por eso cada evento trae su propio id (idempotencia aguas abajo), el id del agregado
 * y el momento exacto.
 */
interface DomainEvent
{
    /**
     * Id único del evento. Sirve de clave de idempotencia en el outbox y en los consumidores.
     */
    public function eventId(): Uuid;

    /**
     * Id del agregado que produjo el evento (como texto, para no acoplar a un tipo concreto).
     */
    public function aggregateId(): string;

    /**
     * Nombre estable del evento, en snake_case con contexto: "network.node_registered".
     */
    public function eventName(): string;

    public function occurredAt(): DateTimeImmutable;

    /**
     * Carga útil serializable a JSON (solo primitivos: strings, ints, floats, bools, arrays).
     *
     * @return array<string, mixed>
     */
    public function payload(): array;
}
