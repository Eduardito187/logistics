<?php

declare(strict_types=1);

use Logistics\Access\Application\Query\GetIntegrationToken\GetIntegrationToken;
use Logistics\Access\Application\Query\GetRole\GetRole;
use Logistics\Access\Application\Query\GetUser\GetUser;
use Logistics\Access\Application\Query\ListIntegrationTokens\ListIntegrationTokens;
use Logistics\Access\Application\Query\ListPermissions\ListPermissions;
use Logistics\Access\Application\Query\ListRoles\ListRoles;
use Logistics\Access\Application\Query\ListUsers\ListUsers;
use Logistics\Access\Application\ReadModel\IntegrationTokenView;
use Logistics\Access\Application\ReadModel\PermissionView;
use Logistics\Access\Application\ReadModel\RoleView;
use Logistics\Access\Application\ReadModel\UserView;
use Logistics\Access\Domain\ValueObject\IntegrationTokenId;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\IntegrationTokenFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\PermissionFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\RoleFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\UserFactory;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Cobertura de los Query handlers de lectura del contexto Access y de sus ReadModels (vistas).
 *
 * Se siembran filas reales con las fábricas del contexto (que escriben los records que leen los
 * repositorios Eloquent) y se consulta por el QueryBus, igual que en producción. Se asertan la forma
 * del resultado y el tipo de la vista proyectada. Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->queries = app(QueryBus::class);
});

it('lista usuarios y filtra por estado', function (): void {
    /** @var QueryBus $queries */
    $queries = $this->queries;

    UserFactory::new()->count(2)->create();
    UserFactory::new()->suspended()->create();

    /** @var list<UserView> $todos */
    $todos = $queries->ask(new ListUsers);
    expect($todos)->toHaveCount(3)
        ->and($todos[0])->toBeInstanceOf(UserView::class);

    /** @var list<UserView> $suspendidos */
    $suspendidos = $queries->ask(new ListUsers(status: 'suspended'));
    expect($suspendidos)->toHaveCount(1)
        ->and($suspendidos[0]->status)->toBe('suspended');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('obtiene un usuario por id y devuelve null si no existe', function (): void {
    /** @var QueryBus $queries */
    $queries = $this->queries;

    $record = UserFactory::new()->create();

    $view = $queries->ask(new GetUser($record->getKey()));
    expect($view)->toBeInstanceOf(UserView::class)
        ->and($view->id)->toBe($record->getKey());

    expect($queries->ask(new GetUser(UserId::generate()->toString())))->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lista roles como vistas', function (): void {
    /** @var QueryBus $queries */
    $queries = $this->queries;

    RoleFactory::new()->count(2)->create();

    /** @var list<RoleView> $roles */
    $roles = $queries->ask(new ListRoles);
    expect($roles)->toHaveCount(2)
        ->and($roles[0])->toBeInstanceOf(RoleView::class);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('obtiene un rol por id y devuelve null si no existe', function (): void {
    /** @var QueryBus $queries */
    $queries = $this->queries;

    $record = RoleFactory::new()->create();

    $view = $queries->ask(new GetRole($record->getKey()));
    expect($view)->toBeInstanceOf(RoleView::class)
        ->and($view->id)->toBe($record->getKey());

    expect($queries->ask(new GetRole(RoleId::generate()->toString())))->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lista permisos y filtra por grupo', function (): void {
    /** @var QueryBus $queries */
    $queries = $this->queries;

    PermissionFactory::new()->create(['key' => 'coverage.read', 'group' => 'coverage', 'action' => 'read']);
    PermissionFactory::new()->create(['key' => 'coverage.write', 'group' => 'coverage', 'action' => 'write']);
    PermissionFactory::new()->create(['key' => 'other.read', 'group' => 'other', 'action' => 'read']);

    /** @var list<PermissionView> $todos */
    $todos = $queries->ask(new ListPermissions);
    expect($todos)->toHaveCount(3)
        ->and($todos[0])->toBeInstanceOf(PermissionView::class);

    /** @var list<PermissionView> $delGrupo */
    $delGrupo = $queries->ask(new ListPermissions(group: 'coverage'));
    expect($delGrupo)->toHaveCount(2);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lista tokens de integración y filtra por estado', function (): void {
    /** @var QueryBus $queries */
    $queries = $this->queries;

    IntegrationTokenFactory::new()->count(2)->create();
    IntegrationTokenFactory::new()->revoked()->create();

    /** @var list<IntegrationTokenView> $todos */
    $todos = $queries->ask(new ListIntegrationTokens);
    expect($todos)->toHaveCount(3)
        ->and($todos[0])->toBeInstanceOf(IntegrationTokenView::class);

    /** @var list<IntegrationTokenView> $revocados */
    $revocados = $queries->ask(new ListIntegrationTokens(status: 'revoked'));
    expect($revocados)->toHaveCount(1)
        ->and($revocados[0]->status)->toBe('revoked');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('obtiene un token de integración por id y devuelve null si no existe', function (): void {
    /** @var QueryBus $queries */
    $queries = $this->queries;

    $record = IntegrationTokenFactory::new()->create();

    $view = $queries->ask(new GetIntegrationToken($record->getKey()));
    expect($view)->toBeInstanceOf(IntegrationTokenView::class)
        ->and($view->id)->toBe($record->getKey());

    expect($queries->ask(new GetIntegrationToken(
        IntegrationTokenId::generate()->toString(),
    )))->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
