<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\IssueVoucher;

use Logistics\Shared\Application\Command\Command;

/**
 * Emite el vale de un caso ya aprobado (normalmente la aprobación ya lo emite; este comando cubre el caso
 * en que se separe el paso). Idempotente: si el caso ya tiene vale, no crea otro.
 */
final readonly class IssueVoucher implements Command
{
    public function __construct(
        public string $caseId,
        public ?string $holderEmail = null,
    ) {}
}
