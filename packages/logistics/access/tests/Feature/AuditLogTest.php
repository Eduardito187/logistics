<?php

declare(strict_types=1);

use Logistics\Access\Application\Command\RecordAuditEntry\RecordAuditEntry;
use Logistics\Access\Application\Query\ListAuditEntries\ListAuditEntries;
use Logistics\Access\Application\ReadModel\AuditEntryView;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\AuditEntryRecord;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Verifica el ciclo de la bitácora de auditoría: el comando RecordAuditEntry graba una fila inmutable en
 * `access_audit_log` y deja además su evento en el outbox (aggregate_type "access.audit"). La consulta
 * ListAuditEntries proyecta esas filas. Corre contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
});

it('graba una entrada de auditoría y deja su evento en el outbox', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;

    $commands->dispatch(new RecordAuditEntry(
        action: 'user.suspended',
        subjectType: 'access.user',
        subjectId: 'user-123',
        actorId: 'admin-1',
        actorLabel: 'Ada Lovelace',
        context: ['reason' => 'offboarding'],
        ipAddress: '10.0.0.1',
    ));

    $record = AuditEntryRecord::query()->firstOrFail();

    expect($record->action)->toBe('user.suspended')
        ->and($record->subject_type)->toBe('access.user')
        ->and($record->subject_id)->toBe('user-123')
        ->and($record->actor_label)->toBe('Ada Lovelace')
        ->and($record->context)->toBe(['reason' => 'offboarding'])
        ->and($record->ip_address)->toBe('10.0.0.1');

    expect(OutboxMessageRecord::query()
        ->where('aggregate_type', 'access.audit')
        ->where('event_name', 'access.audit_entry_recorded')
        ->count())->toBe(1);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('rechaza una acción con formato inválido sin grabar nada', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;

    expect(fn () => $commands->dispatch(new RecordAuditEntry(
        action: 'invalida',
        subjectType: 'access.user',
    )))->toThrow(AccessException::class);

    expect(AuditEntryRecord::query()->count())->toBe(0);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('lista las entradas recientes filtrando por acción', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;
    /** @var QueryBus $queries */
    $queries = $this->queries;

    $commands->dispatch(new RecordAuditEntry(action: 'user.suspended', subjectType: 'access.user', subjectId: 'u1'));
    $commands->dispatch(new RecordAuditEntry(action: 'role.deleted', subjectType: 'access.role', subjectId: 'r1'));

    /** @var list<AuditEntryView> $soloUsuario */
    $soloUsuario = $queries->ask(new ListAuditEntries(limit: 50, action: 'user.suspended'));

    expect($soloUsuario)->toHaveCount(1)
        ->and($soloUsuario[0])->toBeInstanceOf(AuditEntryView::class)
        ->and($soloUsuario[0]->action)->toBe('user.suspended')
        ->and($soloUsuario[0]->subjectId)->toBe('u1');

    /** @var list<AuditEntryView> $todas */
    $todas = $queries->ask(new ListAuditEntries(limit: 50));
    expect($todas)->toHaveCount(2);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);
