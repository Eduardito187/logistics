<?php

declare(strict_types=1);

namespace Logistics\Shared\Application\Command;

/**
 * Despacha un comando a su único handler. La capa de Presentation habla con el bus, no con el handler.
 */
interface CommandBus
{
    public function dispatch(Command $command): void;
}
