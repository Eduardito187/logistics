<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Access\Domain\Model\AuditEntry;
use Logistics\Access\Domain\ValueObject\AuditAction;
use Logistics\Access\Domain\ValueObject\AuditEntryId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\AuditEntryRecord;

/**
 * Traduce entre el agregado AuditEntry (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class AuditEntryMapper
{
    public function toDomain(AuditEntryRecord $record): AuditEntry
    {
        return AuditEntry::reconstitute(
            AuditEntryId::fromString($record->id),
            $record->actor_id,
            $record->actor_label,
            AuditAction::fromString($record->action),
            $record->subject_type,
            $record->subject_id,
            $record->context,
            $record->ip_address,
            DateTimeImmutable::createFromInterface($record->occurred_at),
        );
    }

    /**
     * @return array{id: string, actor_id: string|null, actor_label: string, action: string, subject_type: string, subject_id: string|null, context: array<string, mixed>, ip_address: string|null, occurred_at: DateTimeImmutable}
     */
    public function toRow(AuditEntry $entry): array
    {
        return [
            'id' => $entry->id()->toString(),
            'actor_id' => $entry->actorId(),
            'actor_label' => $entry->actorLabel(),
            'action' => $entry->action()->value,
            'subject_type' => $entry->subjectType(),
            'subject_id' => $entry->subjectId(),
            'context' => $entry->context(),
            'ip_address' => $entry->ipAddress(),
            'occurred_at' => $entry->occurredAt(),
        ];
    }
}
