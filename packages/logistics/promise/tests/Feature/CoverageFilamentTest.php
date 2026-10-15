<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Promise\Infrastructure\Persistence\Eloquent\PromiseRecord;
use Logistics\Promise\Presentation\Filament\Resources\PromiseResource\Pages\ListPromises;
use Logistics\Promise\Presentation\Filament\Resources\PromiseResource\Pages\ViewPromise;

/**
 * Cobertura de la capa de presentación Filament del contexto promise (promesas de entrega): el listado y
 * el detalle. Montar el listado construye las acciones de cabecera (cotizar/reservar/confirmar) y la
 * definición de table(); el detalle ejecuta el infolist con un registro real de la fábrica del contexto.
 *
 * Corre contra logistics_test (RefreshDatabase); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('renderiza el listado de promesas (y construye sus acciones de cabecera)', function (): void {
    Livewire::test(ListPromises::class)->assertOk();
});

it('renderiza el listado de promesas con datos', function (): void {
    PromiseRecord::factory()->count(3)->create();

    Livewire::test(ListPromises::class)->assertOk();
});

it('renderiza el detalle de una promesa', function (): void {
    $promise = PromiseRecord::factory()->create();

    Livewire::test(ViewPromise::class, ['record' => $promise->id])->assertOk();
});
