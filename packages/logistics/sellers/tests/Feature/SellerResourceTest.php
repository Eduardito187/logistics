<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Logistics\Sellers\Database\Factories\SellerFactory;
use Logistics\Sellers\Database\Factories\SellerSettlementTermsFactory;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource\Pages\CreateSeller;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource\Pages\ListSellers;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource\Pages\ViewSeller;

/**
 * Cobertura de la capa Filament del contexto sellers (admin de vendedores de marketplace). Montar cada
 * página Livewire ejecuta las definiciones de table()/form()/infolist() del recurso; el alta y las
 * acciones de fila re-despachan comandos al CommandBus (nunca escriben el record directo).
 *
 * El rol de oficina (admin) satisface el gate OfficeOnly del recurso. Corre contra logistics_test
 * (RefreshDatabase); skip sin PostgreSQL.
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

it('renderiza el listado de sellers', function (): void {
    SellerFactory::new()->create(['legal_name' => 'Marketplace Visible', 'tax_id' => 'NIT-ADM-0001']);

    Livewire::test(ListSellers::class)->assertOk();
});

it('da de alta un seller desde el admin re-despachando RegisterSeller', function (): void {
    Livewire::test(CreateSeller::class)
        ->assertOk()
        ->fillForm([
            'legal_name' => 'Nueva Tienda del Sur S.R.L.',
            'tax_id' => 'NIT-ADM-0002',
            'contact_name' => 'Lucía Soto',
            'contact_email' => 'altas@tiendasur.bo',
            'contact_phone' => '70022002',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('sellers', [
        'tax_id' => 'NIT-ADM-0002',
        'legal_name' => 'Nueva Tienda del Sur S.R.L.',
        'status' => 'onboarding',
    ]);
});

it('da de alta un seller sin teléfono desde el admin', function (): void {
    Livewire::test(CreateSeller::class)
        ->fillForm([
            'legal_name' => 'Tienda Sin Teléfono',
            'tax_id' => 'NIT-ADM-0003',
            'contact_name' => 'Pedro Lima',
            'contact_email' => 'sinfono@tienda.bo',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(DB::table('sellers')->where('tax_id', 'NIT-ADM-0003')->value('contact_phone'))->toBeNull();
});

it('renderiza el hub 360 de un vendedor', function (): void {
    $seller = SellerFactory::new()->active()->create(['legal_name' => 'Hub Vendedor', 'tax_id' => 'NIT-ADM-0004']);

    Livewire::test(ViewSeller::class, ['record' => $seller->getKey()])->assertOk();
});

it('activa un seller desde la acción de fila re-despachando ActivateSeller', function (): void {
    $seller = SellerFactory::new()->create(['tax_id' => 'NIT-ADM-0005']);

    Livewire::test(ListSellers::class)
        ->callTableAction('activate', $seller->getKey());

    expect(DB::table('sellers')->where('id', $seller->getKey())->value('status'))->toBe('active');
});

it('suspende un seller desde la acción de fila re-despachando SuspendSeller', function (): void {
    $seller = SellerFactory::new()->active()->create(['tax_id' => 'NIT-ADM-0006']);

    Livewire::test(ListSellers::class)
        ->callTableAction('suspend', $seller->getKey());

    expect(DB::table('sellers')->where('id', $seller->getKey())->value('status'))->toBe('suspended');
});

it('monta la acción de términos de liquidación sobre un seller sin términos', function (): void {
    $seller = SellerFactory::new()->active()->create(['tax_id' => 'NIT-ADM-0007']);

    // El form de la acción no es driveable por el helper de test (callAction(data:) no lo llena), así que
    // acá solo se cubre el schema + fillForm montando la acción; el efecto del comando ya se ejercita en
    // SellerHttpControllersTest.
    Livewire::test(ListSellers::class)
        ->mountTableAction('settlementTerms', $seller->getKey())
        ->assertOk();
});

it('monta la acción de términos precargando los valores ya pactados', function (): void {
    $seller = SellerFactory::new()->active()->create(['tax_id' => 'NIT-ADM-0008']);
    SellerSettlementTermsFactory::new()
        ->forSeller((string) $seller->getKey())
        ->create([
            'commission_basis_points' => 1500,
            'fixed_fee_cents' => 2000,
            'currency' => 'BOB',
            'payment_cycle' => 'monthly',
        ]);

    // Rama de fillForm con términos existentes (lee la fila por query builder).
    Livewire::test(ListSellers::class)
        ->mountTableAction('settlementTerms', $seller->getKey())
        ->assertOk();
});
