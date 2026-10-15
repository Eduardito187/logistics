<?php

declare(strict_types=1);

use Logistics\Promise\Infrastructure\Persistence\Eloquent\PromiseRecord;

/*
| Cobertura del worker de consola del barrido de reservas vencidas. Siembra una promesa reservada cuya
| ventana ya venció, corre el comando y verifica que termina OK y que la promesa quedó EXPIRED (el barrido
| despachó un ExpireReservation que la expiró y liberó su cupo). También cubre la corrida sin reservas
| vencidas (resumen con cero expiradas).
|
| Corre contra logistics_test (RefreshDatabase); se marca skip sin PostgreSQL.
*/

beforeEach(function (): void {
    //
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('expira las reservas vencidas y termina con éxito', function (): void {
    $promise = PromiseRecord::factory()->reserved()->create([
        'time_slot' => 'AM',
        'reserved_until' => now()->subMinutes(10)->toIso8601String(),
    ]);

    $this->artisan('logistics:promise:expire-reservations')
        ->assertSuccessful();

    expect(PromiseRecord::query()->whereKey($promise->id)->value('status'))->toBe('EXPIRED');
});

it('termina con éxito cuando no hay reservas vencidas', function (): void {
    // Una reserva aún vigente (ventana a futuro) no debe barrerse.
    $promise = PromiseRecord::factory()->reserved()->create(['time_slot' => 'AM']);

    $this->artisan('logistics:promise:expire-reservations')
        ->assertSuccessful();

    expect(PromiseRecord::query()->whereKey($promise->id)->value('status'))->toBe('RESERVED');
});
