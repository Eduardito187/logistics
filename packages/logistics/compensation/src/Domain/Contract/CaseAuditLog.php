<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Contract;

/**
 * Bitácora inmutable (solo INSERT) de las decisiones sobre un caso/vale. Auditoría de §2.1.5: quién, qué,
 * cuándo, con qué datos. La implementación escribe en `compensation_case_log`.
 */
interface CaseAuditLog
{
    /**
     * @param array<string, mixed> $payload datos del hecho (cálculo, override, motivo…)
     */
    public function record(string $caseId, ?string $voucherId, string $action, string $actor, array $payload): void;
}
