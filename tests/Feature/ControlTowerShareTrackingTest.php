<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Pages\ControlTower;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

// "Compartir seguimiento" de la Torre de Control: resuelve el enlace PÚBLICO (página t/{token}) del envío
// señalado a partir de su token de seguimiento. Null si el envío no tiene seguimiento abierto.

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('arma el enlace público de seguimiento desde el token del envío', function (): void {
    $shipmentId = (string) Str::uuid7();
    $token = 'tok-'.Str::random(20);
    DB::table('tracking_shipments')->insert([
        'id' => (string) Str::uuid7(),
        'shipment_id' => $shipmentId,
        'token' => $token,
        'status' => 'IN_TRANSIT',
        'remaining_stops' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $url = Livewire::test(ControlTower::class)->instance()->shareTrackingUrl($shipmentId);

    expect($url)->toBe(route('tracking.public.page', ['token' => $token]))
        ->and($url)->toContain('/t/'.$token);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('devuelve null cuando el envío no tiene seguimiento abierto', function (): void {
    $url = Livewire::test(ControlTower::class)->instance()->shareTrackingUrl((string) Str::uuid7());

    expect($url)->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
