<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\RejectCompensationCase;

use Logistics\Shared\Application\Command\Command;

/**
 * Rechaza un caso propuesto con un motivo. Cuatro ojos: quien propuso no puede rechazar.
 */
final readonly class RejectCompensationCase implements Command
{
    public function __construct(
        public string $caseId,
        public string $approver,
        public string $reason,
    ) {}
}
