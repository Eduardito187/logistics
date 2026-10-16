<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Pages\ControlTower;
use App\Filament\Pages\CoverageMap;
use App\Filament\Pages\CustomerProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;

// Ejercita el render y las acciones Livewire de las páginas Filament propias del panel (App\Filament):
// la torre de control, el mapa de cobertura y la ficha de cliente. Montar cada página corre mount(),
// getViewData() y, al llamar métodos, sus ramas (guardar/cargar zona, agrupar pedidos). No se caza nada.

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza la torre de control como landing operativo', function (): void {
    Livewire::test(ControlTower::class)->assertOk();
});

it('deja la torre de control como landing universal para cualquier rol del panel', function (): void {
    // Admin (del beforeEach) accede.
    expect(ControlTower::canAccess())->toBeTrue();

    // Un rol de campo también accede al landing: los widgets se gatean solos por rol, así nadie recibe
    // un 403 al iniciar sesión (la torre es la página de aterrizaje del admin para todos los roles).
    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);
    $this->actingAs($driver);
    expect(ControlTower::canAccess())->toBeTrue();
});

it('renderiza el mapa de cobertura y reinicia el formulario de zona', function (): void {
    Livewire::test(CoverageMap::class)
        ->assertOk()
        ->call('newZone')
        ->call('loadZone'); // sin zoneId seleccionado: no hace nada
});

it('guarda una zona de cobertura dibujada en el mapa', function (): void {
    $geoJson = json_encode([
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [-68.15, -16.50],
                [-68.10, -16.50],
                [-68.10, -16.45],
                [-68.15, -16.45],
                [-68.15, -16.50],
            ]],
        ],
    ]);

    Livewire::test(CoverageMap::class)
        ->set('name', 'Zona Sur')
        ->set('drawnGeoJson', (string) $geoJson)
        ->call('save')
        ->assertOk();
});

it('avisa cuando se intenta guardar sin un polígono dibujado', function (): void {
    // Un JSON sin coordenadas: extractRing devuelve null y la página avisa que falta dibujar.
    Livewire::test(CoverageMap::class)
        ->set('name', 'Zona sin polígono')
        ->set('drawnGeoJson', '{}')
        ->call('save')
        ->assertOk();
});

it('renderiza la ficha de cliente sin cliente seleccionado', function (): void {
    Livewire::test(CustomerProfile::class)->assertOk();
});

it('renderiza la ficha de cliente con sus pedidos, direcciones y mapa', function (): void {
    // Varios pedidos del mismo cliente a la misma dirección para ejercitar el agrupado y los puntos.
    ShipmentRecordFactory::new()->count(3)->create([
        'customer_name' => 'Juan Perez',
        'address_text' => 'Av. Siempre Viva 742, La Paz',
        'lat' => -16.5000000,
        'lng' => -68.1500000,
    ]);

    Livewire::test(CustomerProfile::class)
        ->set('customer', 'Juan Perez')
        ->assertOk();
});
