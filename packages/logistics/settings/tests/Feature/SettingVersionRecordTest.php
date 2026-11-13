<?php

declare(strict_types=1);

use Logistics\Settings\Infrastructure\Persistence\Eloquent\SettingVersionRecord;

/**
 * Ejercita el modelo Eloquent de `settings_versions`: su factory enlazada explícitamente (el package
 * está en dont-discover) y los casts de columnas (value jsonb → arreglo, version → entero). Las filas
 * son inmutables; la factory solo las crea. Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('crea una fila con la factory y castea value a arreglo y version a entero', function (): void {
    $record = SettingVersionRecord::factory()
        ->forKey('coverage.record_sample')
        ->version(3)
        ->withValue(['days' => 4, 'enabled' => true])
        ->create();

    $fresh = SettingVersionRecord::query()->findOrFail($record->getKey());

    expect($fresh->key)->toBe('coverage.record_sample')
        ->and($fresh->version)->toBe(3)
        ->and($fresh->value)->toBe(['days' => 4, 'enabled' => true]);
});

it('la factory expone su modelo asociado', function (): void {
    expect(SettingVersionRecord::factory()->modelName())->toBe(SettingVersionRecord::class);
});
