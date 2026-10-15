<?php

declare(strict_types=1);

use Logistics\Access\Domain\Event\AuditEntryRecorded;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\Model\AuditEntry;
use Logistics\Access\Domain\ValueObject\AuditAction;
use Logistics\Access\Domain\ValueObject\AuditEntryId;

/**
 * Graba una entrada de auditoría de dominio con valores por defecto razonables.
 *
 * @param array<string, mixed> $context
 */
function grabarEntradaAuditoria(
    ?string $actorId = 'actor-1',
    string $actorLabel = 'Ada Lovelace',
    string $action = 'user.suspended',
    string $subjectType = 'access.user',
    ?string $subjectId = 'user-1',
    array $context = [],
    ?string $ip = '10.0.0.1',
): AuditEntry {
    return AuditEntry::record(
        AuditEntryId::generate(),
        $actorId,
        $actorLabel,
        AuditAction::fromString($action),
        $subjectType,
        $subjectId,
        $context,
        $ip,
        new DateTimeImmutable('2026-10-13T12:00:00+00:00'),
    );
}

it('graba una entrada y emite el evento con su carga', function (): void {
    $entry = grabarEntradaAuditoria(context: ['added' => ['picker']]);

    expect($entry->actorLabel())->toBe('Ada Lovelace')
        ->and($entry->action()->value)->toBe('user.suspended')
        ->and($entry->subjectType())->toBe('access.user')
        ->and($entry->subjectId())->toBe('user-1')
        ->and($entry->context())->toBe(['added' => ['picker']])
        ->and($entry->ipAddress())->toBe('10.0.0.1');

    $events = $entry->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AuditEntryRecorded::class)
        ->and($events[0]->eventName())->toBe('access.audit_entry_recorded')
        ->and($events[0]->payload())->toBe([
            'actor_id' => 'actor-1',
            'actor_label' => 'Ada Lovelace',
            'action' => 'user.suspended',
            'subject_type' => 'access.user',
            'subject_id' => 'user-1',
            'context' => ['added' => ['picker']],
            'ip_address' => '10.0.0.1',
        ]);
});

it('atribuye al sistema cuando no hay autor', function (): void {
    $entry = grabarEntradaAuditoria(actorId: null, actorLabel: '   ');

    expect($entry->actorId())->toBeNull()
        ->and($entry->actorLabel())->toBe('System');
});

it('rechaza una entrada sin tipo de sujeto', function (): void {
    expect(fn () => grabarEntradaAuditoria(subjectType: '  '))->toThrow(AccessException::class);
});

it('reconstituye sin volver a emitir eventos', function (): void {
    $entry = AuditEntry::reconstitute(
        AuditEntryId::generate(),
        'actor-1',
        'Ada Lovelace',
        AuditAction::fromString('role.deleted'),
        'access.role',
        'role-1',
        [],
        null,
        new DateTimeImmutable('2026-10-13T12:00:00+00:00'),
    );

    expect($entry->releaseEvents())->toBe([]);
});
