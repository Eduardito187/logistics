<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\AcknowledgeAlert;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de reconocer (acknowledge) una alerta: quién la toma.
 */
final readonly class AcknowledgeAlert implements Command
{
    public function __construct(
        public string $alertId,
        public string $by,
    ) {}
}
