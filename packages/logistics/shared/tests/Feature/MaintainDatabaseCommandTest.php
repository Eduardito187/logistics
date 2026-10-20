<?php

declare(strict_types=1);

// Ejercita el comando de mantenimiento físico logistics:db:maintain. Usa --analyze-only porque los
// tests Feature corren dentro de una transacción (RefreshDatabase) y VACUUM no puede correr en una
// transacción; ANALYZE sí. Las tablas calientes existen en la base de test tras las migraciones.

it('corre ANALYZE sobre todas las tablas calientes por defecto', function (): void {
    $this->artisan('logistics:db:maintain', ['--analyze-only' => true])
        ->assertSuccessful();
});

it('acepta un subconjunto de tablas con --tables', function (): void {
    $this->artisan('logistics:db:maintain', [
        '--analyze-only' => true,
        '--tables' => 'telemetry_pings,outbox_messages',
    ])->assertSuccessful();
});

it('rechaza una tabla fuera de la allowlist', function (): void {
    $this->artisan('logistics:db:maintain', [
        '--analyze-only' => true,
        '--tables' => 'users',
    ])->assertFailed();
});
