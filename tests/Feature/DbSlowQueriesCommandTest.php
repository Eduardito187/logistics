<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// Comando logistics:db:slow-queries (auditoría M-6): consume pg_stat_statements para diagnóstico y
// degrada con gracia si la extensión no está disponible (p. ej. la base de test).

it('runs successfully and degrades gracefully when pg_stat_statements is unavailable', function (): void {
    $installed = DB::selectOne("select 1 as ok from pg_extension where extname = 'pg_stat_statements'") !== null;

    $command = $this->artisan('logistics:db:slow-queries', ['--limit' => 5]);

    if ($installed) {
        // Con la extensión instalada: termina ok (puede o no tener filas registradas aún).
        $command->assertSuccessful();
    } else {
        // Sin la extensión: avisa y termina ok, sin reventar.
        $command->expectsOutputToContain('pg_stat_statements no está disponible')
            ->assertSuccessful();
    }
});

it('accepts the mean order option', function (): void {
    $this->artisan('logistics:db:slow-queries', ['--order' => 'mean'])->assertSuccessful();
});
