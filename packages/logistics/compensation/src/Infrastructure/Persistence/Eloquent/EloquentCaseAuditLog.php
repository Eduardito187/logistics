<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Persistence\Eloquent;

use Logistics\Compensation\Domain\Contract\CaseAuditLog;
use Logistics\Compensation\Domain\ValueObject\CompensationCaseId;
use Logistics\Compensation\Infrastructure\Persistence\Eloquent\Model\CompensationCaseLogRecord;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Bitácora inmutable sobre `compensation_case_log`: solo INSERT. Corre en la misma transacción del comando,
 * así la decisión y su rastro de auditoría se guardan juntos.
 */
final readonly class EloquentCaseAuditLog implements CaseAuditLog
{
    public function __construct(
        private Clock $clock,
    ) {}

    public function record(string $caseId, ?string $voucherId, string $action, string $actor, array $payload): void
    {
        CompensationCaseLogRecord::query()->create([
            'id' => CompensationCaseId::generate()->toString(),
            'case_id' => $caseId,
            'voucher_id' => $voucherId,
            'action' => $action,
            'actor' => $actor,
            'payload' => $payload,
            'created_at' => $this->clock->now(),
        ]);
    }
}
