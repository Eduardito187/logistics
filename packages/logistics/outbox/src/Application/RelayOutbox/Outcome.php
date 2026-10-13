<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\RelayOutbox;

/**
 * Desenlace del despacho de un mensaje dentro del lote. Uso interno del relay para contabilizar.
 */
enum Outcome
{
    case Dispatched;
    case Failed;
    case DeadLettered;
}
