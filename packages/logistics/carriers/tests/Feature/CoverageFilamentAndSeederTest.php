<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\SettlementFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\TariffFactory;
use Logistics\Carriers\Infrastructure\Persistence\Seeder\CarriersDemoSeeder;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource\Pages\CreateCarrier;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource\Pages\ListCarriers;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource\Pages\ViewCarrier;
use Logistics\Carriers\Presentation\Filament\Resources\SettlementResource\Pages\ListSettlements;
use Logistics\Carriers\Presentation\Filament\Resources\SettlementResource\Pages\ViewSettlement;
use Logistics\Carriers\Presentation\Filament\Resources\TariffResource\Pages\CreateTariff;
use Logistics\Carriers\Presentation\Filament\Resources\TariffResource\Pages\ListTariffs;
use Logistics\Carriers\Presentation\Filament\Resources\TariffResource\Pages\ViewTariff;

/**
 * Cobertura de la capa de presentación Filament del contexto carriers (transportistas): recursos de
 * transportistas, tarifas y liquidaciones. Montar cada página Livewire ejecuta las definiciones de
 * table()/form()/infolist(); las vistas de detalle usan registros reales de las fábricas del contexto.
 * Además ejercita el seeder de demo (Pattern D).
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

it('renderiza el listado de transportistas', function (): void {
    Livewire::test(ListCarriers::class)->assertOk();
});

it('renderiza el alta de transportista', function (): void {
    Livewire::test(CreateCarrier::class)->assertOk();
});

it('renderiza el detalle de un transportista', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Transportes Andes SRL']);

    Livewire::test(ViewCarrier::class, ['record' => $carrier->id])->assertOk();
});

it('renderiza el listado de tarifas', function (): void {
    Livewire::test(ListTariffs::class)->assertOk();
});

it('renderiza el alta de tarifa', function (): void {
    // El select de transportista (relationship) necesita al menos un transportista real para poblarse.
    CarrierFactory::new()->create(['name' => 'Logística del Sur']);

    Livewire::test(CreateTariff::class)->assertOk();
});

it('renderiza el detalle de una tarifa', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Flota Altiplano']);
    $tariff = TariffFactory::new()
        ->forCarrier($carrier->id)
        ->forCorridor('CD-LPZ', 'CD-SCZ', 'cd_to_cd')
        ->forVehicleClass('trailer')
        ->create();

    Livewire::test(ViewTariff::class, ['record' => $tariff->id])->assertOk();
});

it('renderiza el listado de liquidaciones', function (): void {
    Livewire::test(ListSettlements::class)->assertOk();
});

it('renderiza el detalle de una liquidación', function (): void {
    $carrier = CarrierFactory::new()->create(['name' => 'Cargo Express Bolivia']);
    $settlement = SettlementFactory::new()->forCarrier($carrier->id)->create();

    Livewire::test(ViewSettlement::class, ['record' => $settlement->id])->assertOk();
});

it('siembra la demo de transportistas sin reventar y es idempotente', function (): void {
    $this->seed(CarriersDemoSeeder::class);
    expect(DB::table('carriers')->count())->toBeGreaterThan(0);

    $count = DB::table('carriers')->count();
    // Segunda corrida: idempotente, no duplica transportistas de demo.
    $this->seed(CarriersDemoSeeder::class);
    expect(DB::table('carriers')->count())->toBe($count);
});
