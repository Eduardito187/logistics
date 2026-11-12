<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Contract;

use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Observador (plugin) del procesamiento del outbox: punto de extensión GENÉRICO para que un contexto
 * "escuche" los hechos que pasan por el relay sin ser un reactor del despacho.
 *
 * Diferencia con MessageDispatcher:
 *  - Un dispatcher ES el despacho: si lanza, el mensaje queda Failed, se reintenta con backoff y puede
 *    terminar en dead-letter. Además, en un abanico un dispatcher que lanza corta a los siguientes.
 *  - Un observador NO participa del desenlace: ProcessOutboxMessage lo invoca DESPUÉS de persistir el
 *    resultado del despacho, envuelto en try/catch que traga y loguea cualquier excepción. Un observador
 *    jamás puede cambiar el estado del mensaje, sus reintentos ni el dead-letter.
 *
 * Contrato para implementarlo:
 *  - Debe ser liviano (p. ej. registrar una fila y encolar un job): corre dentro del worker del outbox y
 *    nunca debe hacer I/O externo síncrono (HTTP a terceros) aquí.
 *  - Debe ser idempotente por `eventId`: se lo invoca en CADA procesamiento del mensaje (también en los
 *    reintentos de un despacho fallido), así que puede ver el mismo evento más de una vez.
 *  - Debería no lanzar; si lanza, el relay lo traga y lo deja en el log.
 *
 * Se registran en la config `logistics-outbox.observers` (lista de class-string ligados en el contenedor);
 * cada contexto puede agregarse a esa lista desde su propio provider (como hace el conector SAP).
 */
interface OutboxObserver
{
    public function observe(OutboxMessage $message): void;
}
