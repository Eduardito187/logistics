<?php

declare(strict_types=1);

use Logistics\Access\Application\Command\DefineRole\DefineRole;
use Logistics\Access\Application\Command\RegisterUser\RegisterUser;
use Logistics\Access\Application\Query\AuthenticateUser\AuthenticateUser;
use Logistics\Access\Application\ReadModel\AuthenticatedUserView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Registro de usuario + definición de rol y autenticación real contra PostgreSQL (base logistics_test).
 * Todo pasa por los buses CQRS, igual que en producción. Se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
});

it('autentica a un usuario activo con la contraseña correcta', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;
    /** @var QueryBus $queries */
    $queries = $this->queries;

    $commands->dispatch(new DefineRole('picker', 'Picker', null, ['shipments.read', 'execution.write']));
    $commands->dispatch(new RegisterUser('Ada Lovelace', 'ada@dismac.test', 'password123', ['picker']));

    $auth = $queries->ask(new AuthenticateUser('ada@dismac.test', 'password123'));

    expect($auth)->toBeInstanceOf(AuthenticatedUserView::class);
    /** @var AuthenticatedUserView $auth */
    expect($auth->email)->toBe('ada@dismac.test')
        ->and($auth->roleKeys)->toBe(['picker']);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('devuelve null con la contraseña incorrecta', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;
    /** @var QueryBus $queries */
    $queries = $this->queries;

    $commands->dispatch(new RegisterUser('Grace Hopper', 'grace@dismac.test', 'password123'));

    expect($queries->ask(new AuthenticateUser('grace@dismac.test', 'incorrecta')))->toBeNull();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('devuelve null para un correo que no existe', function (): void {
    /** @var QueryBus $queries */
    $queries = $this->queries;

    expect($queries->ask(new AuthenticateUser('nadie@dismac.test', 'password123')))->toBeNull();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);
