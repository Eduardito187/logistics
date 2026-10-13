<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\RelayOutbox;

/**
 * Resumen de una corrida del relay: cuántos mensajes se tomaron y cómo terminó cada uno.
 *
 * Lo usa el comando de consola para reportar números y el scheduler/monitor para alertar si crece
 * la cola de dead letters.
 */
final readonly class RelayOutboxResult
{
    public function __construct(
        public int $pulled,
        public int $dispatched,
        public int $failed,
        public int $deadLettered,
    ) {}
}
