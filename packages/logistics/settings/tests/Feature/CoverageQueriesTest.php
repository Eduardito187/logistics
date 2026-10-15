<?php

declare(strict_types=1);

use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Application\Query\GetSetting\GetSettingHistoryQuery;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Cobertura del lado CQRS del contexto Configuración: el comando SetSetting versiona una clave y las
 * consultas GetSetting / GetSettingHistory la leen (valor vigente e historial), proyectando SettingView.
 * Todo pasa por los buses, igual que en producción. Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
});

it('publica una clave y lee su valor vigente', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;
    /** @var QueryBus $queries */
    $queries = $this->queries;

    $commands->dispatch(new SetSettingCommand(
        key: 'coverage.cutoff',
        value: ['time' => '14:00'],
        effectiveFrom: null,
        createdBy: 'test',
    ));

    $value = $queries->ask(new GetSettingQuery('coverage.cutoff'));
    expect($value)->toBe(['time' => '14:00']);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('devuelve null para una clave sin versiones', function (): void {
    /** @var QueryBus $queries */
    $queries = $this->queries;

    expect($queries->ask(new GetSettingQuery('coverage.inexistente')))->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lee el historial completo de una clave, de la más nueva a la más vieja', function (): void {
    /** @var CommandBus $commands */
    $commands = $this->commands;
    /** @var QueryBus $queries */
    $queries = $this->queries;

    $commands->dispatch(new SetSettingCommand('coverage.retry', ['max' => 3], null, 'test'));
    $commands->dispatch(new SetSettingCommand('coverage.retry', ['max' => 5], null, 'test'));

    /** @var list<array{key: string, version: int, value: array<string, mixed>}> $historial */
    $historial = $queries->ask(new GetSettingHistoryQuery('coverage.retry'));

    expect($historial)->toHaveCount(2)
        ->and($historial[0]['key'])->toBe('coverage.retry')
        ->and($historial[0]['version'])->toBe(2)
        ->and($historial[1]['version'])->toBe(1);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
