<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Infrastructure\Integration\IntegrationConfig;
use Logistics\Settings\Infrastructure\Integration\IntegrationKeys;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * IntegrationConfig se lee en el boot de CADA request (overrides de integraciones). Debe ir a cache,
 * incluido el caso "no configurado", para no consultar settings_versions por request, y la publicación
 * de una versión nueva debe invalidarlo (lectura fresca inmediata).
 */
beforeEach(function (): void {
    cache()->flush();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function countSettingsQueries(Closure $fn): int
{
    $count = 0;
    DB::listen(static function ($q) use (&$count): void {
        if (str_contains($q->sql, 'settings_versions')) {
            $count++;
        }
    });
    $fn();

    return $count;
}

it('cachea también el caso "no configurado": la segunda lectura no va a la base', function (): void {
    $first = countSettingsQueries(fn () => expect(app(IntegrationConfig::class)->config(IntegrationKeys::ROUTING))->toBe([]));
    $second = countSettingsQueries(fn () => expect(app(IntegrationConfig::class)->config(IntegrationKeys::ROUTING))->toBe([]));

    expect($first)->toBe(1)->and($second)->toBe(0);
});

it('memoiza por instancia: enabled/value/secret de la misma clave hacen una sola lectura', function (): void {
    $config = app(IntegrationConfig::class);

    $queries = countSettingsQueries(function () use ($config): void {
        $config->enabled(IntegrationKeys::SAP);
        $config->value(IntegrationKeys::SAP, 'base_url');
        $config->secret(IntegrationKeys::SAP, 'password');
    });

    expect($queries)->toBe(1);
});

it('publicar una versión nueva invalida el cache y la próxima lectura ve el valor nuevo', function (): void {
    expect(app(IntegrationConfig::class)->enabled(IntegrationKeys::ROUTING))->toBeFalse();

    app(CommandBus::class)->dispatch(new SetSettingCommand(
        IntegrationKeys::ROUTING,
        ['enabled' => true, 'osrm_host' => 'http://osrm.test'],
        null,
        'tester',
    ));

    $fresh = app(IntegrationConfig::class);
    expect($fresh->enabled(IntegrationKeys::ROUTING))->toBeTrue()
        ->and($fresh->value(IntegrationKeys::ROUTING, 'osrm_host'))->toBe('http://osrm.test');
});
