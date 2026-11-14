<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\ApproveCompensationCase;

use Logistics\Shared\Application\Command\Command;

/**
 * Aprueba un caso propuesto y emite su vale. `overrideCents` solo puede BAJAR el monto (nunca subirlo).
 * El permiso y los cuatro ojos los valida el dominio/presentación.
 */
final readonly class ApproveCompensationCase implements Command
{
    public function __construct(
        public string $caseId,
        public string $approver,
        public ?int $overrideCents = null,
        public ?string $holderEmail = null,
    ) {}
}
