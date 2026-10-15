<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Settings\Database\Seeders\SettingsSeeder;

/**
 * Cobertura del seeder del contexto Configuración: publica las reglas base por defecto vía el bus CQRS
 * y es idempotente por clave (no infla el historial al re-ejecutarse). Corre contra PostgreSQL
 * (logistics_test); skip sin él.
 */
it('siembra las reglas base por defecto sin reventar', function (): void {
    $this->seed(SettingsSeeder::class);

    expect(DB::table('settings_versions')->count())->toBeGreaterThan(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('es idempotente: re-ejecutarlo no agrega versiones nuevas', function (): void {
    $this->seed(SettingsSeeder::class);
    $antes = DB::table('settings_versions')->count();

    $this->seed(SettingsSeeder::class);

    expect(DB::table('settings_versions')->count())->toBe($antes);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
