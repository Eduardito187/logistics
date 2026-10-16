<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Subscriber;

/**
 * Suscriptor a un evento de dominio de OTRO contexto, consumido por su NOMBRE y su payload primitivo.
 *
 * Mantiene al contexto `inventory` EXTRAÍBLE a microservicio: no hay dependencia composer al contexto
 * emisor; el enganche es el nombre del evento (string) y un array primitivo. El REGISTRO de la instancia
 * en el relay/dispatcher del outbox lo hace el orquestador; acá solo queda la clase lista.
 */
interface DomainEventSubscriber
{
    /**
     * Nombre namespaced del evento al que reacciona (p. ej. "inbound.goods_received").
     */
    public function subscribedTo(): string;

    /**
     * Reacciona al evento. `$payload` es el array primitivo del evento (`DomainEvent::payload()`).
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
