<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Contract;

use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Despacha un mensaje del outbox hacia su destino externo (SAP, FCM, email, SMS, webhook).
 *
 * La implementación real por canal la aportan otros contextos (o el conector SAP). Acá solo se define
 * el contrato. Si el despacho falla, la implementación DEBE lanzar: el relay captura la excepción,
 * aplica el backoff y reintenta. Debe ser tolerante a reentregas (at-least-once): el mismo mensaje
 * puede llegar más de una vez, y el consumidor se desduplica por eventId.
 */
interface MessageDispatcher
{
    public function dispatch(OutboxMessage $message): void;
}
