<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Access\Domain\Contract\AuditLogRepository;
use Logistics\Access\Domain\Model\AuditEntry;
use Logistics\Access\Domain\ValueObject\AuditEntryId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Mapper\AuditEntryMapper;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\AuditEntryRecord;

/**
 * Implementación Eloquent de la bitácora de auditoría. Es append-only: `append` inserta una fila nueva
 * y nunca actualiza una existente (las entradas son inmutables).
 */
final readonly class EloquentAuditLogRepository implements AuditLogRepository
{
    public function __construct(
        private AuditEntryMapper $mapper = new AuditEntryMapper,
    ) {}

    public function nextIdentity(): AuditEntryId
    {
        return AuditEntryId::generate();
    }

    public function append(AuditEntry $entry): void
    {
        AuditEntryRecord::query()->create($this->mapper->toRow($entry));
    }

    public function recent(int $limit, ?string $action = null, ?string $subjectType = null): array
    {
        $query = AuditEntryRecord::query()
            ->orderByDesc('occurred_at')
            ->limit(max(1, $limit));

        if ($action !== null && $action !== '') {
            $query->where('action', $action);
        }

        if ($subjectType !== null && $subjectType !== '') {
            $query->where('subject_type', $subjectType);
        }

        return array_values($query->get()
            ->map(fn (AuditEntryRecord $record): AuditEntry => $this->mapper->toDomain($record))
            ->all());
    }
}
