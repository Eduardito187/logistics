<?php

declare(strict_types=1);

use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Ejercita el comando de consola `logistics:settings:get`: imprime como JSON el valor vigente de una
 * clave (opcionalmente a una fecha dada con `--at`) y falla cuando la clave no tiene valor vigente. La
 * Presentation habla con el QueryBus, nunca con el handler ni el repositorio. Contra PostgreSQL
 * (base logistics_test).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('imprime el valor vigente de una clave', function (): void {
    app(CommandBus::class)->dispatch(new SetSettingCommand(
        key: 'coverage.console_sample',
        value: ['days' => 7],
        effectiveFrom: null,
        createdBy: 'tester',
    ));

    $this->artisan('logistics:settings:get', ['key' => 'coverage.console_sample'])
        ->expectsOutputToContain('"days": 7')
        ->assertExitCode(0);
});

it('acepta la opción --at para leer el valor a una fecha', function (): void {
    app(CommandBus::class)->dispatch(new SetSettingCommand(
        key: 'coverage.console_at_sample',
        value: ['enabled' => true],
        effectiveFrom: null,
        createdBy: 'tester',
    ));

    $this->artisan('logistics:settings:get', [
        'key' => 'coverage.console_at_sample',
        '--at' => now()->toIso8601String(),
    ])
        ->expectsOutputToContain('"enabled": true')
        ->assertExitCode(0);
});

it('falla cuando la clave no tiene valor vigente', function (): void {
    $this->artisan('logistics:settings:get', ['key' => 'coverage.console_missing'])
        ->assertExitCode(1);
});
