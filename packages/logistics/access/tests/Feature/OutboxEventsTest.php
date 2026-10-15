<?php

declare(strict_types=1);

use Logistics\Access\Application\Command\DefineRole\DefineRole;
use Logistics\Access\Application\Command\RegisterPermission\RegisterPermission;
use Logistics\Access\Application\Command\RegisterUser\RegisterUser;
use Logistics\Access\Application\Service\IntegrationTokenIssuer;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Verifica el patrón outbox transaccional del contexto: cada mutación deja su evento de dominio en
 * `outbox_messages` con aggregate_type bajo el prefijo "access.%". Corre contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->commands = app(CommandBus::class);
});

/**
 * Cuenta los mensajes del outbox de este contexto para un hecho (event_name) dado.
 */
function outboxAccessCount(string $eventName): int
{
    return OutboxMessageRecord::query()
        ->where('aggregate_type', 'like', 'access.%')
        ->where('event_name', $eventName)
        ->count();
}

it('registra un permiso dejando su evento en el outbox', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;

    $commands->dispatch(new RegisterPermission('shipments.read', 'Read shipments'));

    expect(outboxAccessCount('access.permission_registered'))->toBe(1);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('define un rol dejando su evento en el outbox', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;

    $commands->dispatch(new DefineRole('picker', 'Picker', null, ['shipments.read']));

    expect(outboxAccessCount('access.role_defined'))->toBe(1);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('registra un usuario dejando su evento en el outbox', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;

    $commands->dispatch(new RegisterUser('Ada Lovelace', 'ada@dismac.test', 'password123'));

    expect(outboxAccessCount('access.user_registered'))->toBe(1);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('emite un token de integración dejando su evento en el outbox', function (): void {
    $issuer = app(IntegrationTokenIssuer::class);

    $issuer->issue('ERP Integration', ['shipments.read'], null, 'admin@dismac.test');

    expect(outboxAccessCount('access.integration_token_issued'))->toBe(1)
        ->and(OutboxMessageRecord::query()->where('aggregate_type', 'like', 'access.%')->count())->toBeGreaterThanOrEqual(1);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);
