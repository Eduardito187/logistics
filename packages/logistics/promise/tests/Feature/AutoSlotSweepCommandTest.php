<?php

declare(strict_types=1);

/*
| Cobertura del worker de consola del barrido de auto-slot (red de seguridad del Módulo 1). Corre el
| comando contra la base vacía (sin envíos sloteables): no slotea nada, imprime el resumen y termina OK.
| También cubre el saneo de --limit (valor no numérico cae al default) y el límite explícito.
|
| Corre contra logistics_test (RefreshDatabase); se marca skip sin PostgreSQL.
*/

beforeEach(function (): void {
    //
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('termina con éxito cuando no hay envíos por slotear', function (): void {
    $this->artisan('logistics:promise:autoslot-sweep')
        ->assertSuccessful();
});

it('acepta un límite explícito y termina con éxito', function (): void {
    $this->artisan('logistics:promise:autoslot-sweep', ['--limit' => 50])
        ->assertSuccessful();
});

it('sanea un --limit no numérico cayendo al default y termina con éxito', function (): void {
    $this->artisan('logistics:promise:autoslot-sweep', ['--limit' => 'nope'])
        ->assertSuccessful();
});
