<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Query\GetOutboxMessage;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el detalle de un mensaje del outbox por su id. Devuelve null si no existe.
 */
final readonly class GetOutboxMessageQuery implements Query
{
    public function __construct(public string $messageId) {}
}
