<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Logistics\Settlement\Database\Seeders\SettlementDemoSeeder;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Factory\SellerSettlementFactory;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Factory\SellerSettlementLineFactory;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementRecord;
use Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource\Pages\ListSellerSettlements;
use Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource\Pages\ViewSellerSettlement;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de la presentación Filament del contexto Settlement (Patrón D, como CoverageFilamentAndSeederTest
 * de carriers): montar cada página Livewire ejecuta table()/infolist()/getRecordTitle y la resolución
 * cross-context del nombre del seller. Además ejercita el seeder de demo (no-op y siembra real).
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

/**
 * Siembra un seller con razón social legible y lo devuelve.
 */
function settlementResourceSeedSeller(string $legalName): string
{
    $suffix = strtoupper(substr(str_replace('-', '', Uuid::generate()->value), -6));
    $sellerId = Uuid::generate()->value;

    DB::table('sellers')->insert([
        'id' => $sellerId,
        'legal_name' => $legalName,
        'tax_id' => 'NIT-RES-'.$suffix,
        'status' => 'active',
        'contact_name' => 'Owner',
        'contact_email' => 'res'.$suffix.'@seller.bo',
        'contact_phone' => '70000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $sellerId;
}

it('renderiza el listado de liquidaciones con el nombre del seller enriquecido', function (): void {
    $sellerId = settlementResourceSeedSeller('Comercial del Valle SRL');
    SellerSettlementFactory::new()->forSeller($sellerId)->create();
    SellerSettlementFactory::new()->forSeller($sellerId)->confirmed()->create();
    SellerSettlementFactory::new()->forSeller($sellerId)->paid()->create();

    Livewire::test(ListSellerSettlements::class)
        ->assertOk()
        ->assertCanSeeTableRecords(SellerSettlementRecord::query()->get());
});

it('renderiza el detalle de una liquidación con sus despachos', function (): void {
    $sellerId = settlementResourceSeedSeller('Importadora Andina SA');
    $settlement = SellerSettlementFactory::new()->forSeller($sellerId)->create();
    SellerSettlementLineFactory::new()->forSettlement($settlement->id)->count(2)->create();

    Livewire::test(ViewSellerSettlement::class, ['record' => $settlement->id])->assertOk();
});

it('no siembra nada en la demo cuando no hay sellers ni despachos (no-op idempotente)', function (): void {
    $this->seed(SettlementDemoSeeder::class);

    expect(DB::table('seller_settlements')->count())->toBe(0);
});

it('siembra una liquidación de demo cuando ya existen seller y despachos, de forma idempotente', function (): void {
    $sellerId = settlementResourceSeedSeller('Demo Seller SettlementSeeder');

    $shipmentId = Uuid::generate()->value;
    DB::table('shipments')->insert([
        'id' => $shipmentId,
        'source' => 'seller-portal',
        'external_id' => 'DEL-DEMO-1',
        'customer_name' => 'Cliente',
        'address_text' => 'Av. Siempre Viva 123',
        'address_confidence' => 90,
        'address_confidence_level' => 'high',
        'status' => 'DELIVERED',
        'owner_party_id' => $sellerId,
        'fulfillment_mode' => 'fbd',
        'created_at' => '2026-09-10 10:00:00',
        'updated_at' => '2026-09-15 10:00:00',
    ]);
    DB::table('shipment_lines')->insert([
        'id' => Uuid::generate()->value,
        'shipment_id' => $shipmentId,
        'sku' => 'SKU-DEMO-1',
        'product_name' => 'Producto',
        'quantity' => 100,
        'unit_price' => 20.00,
        'unit_price_currency' => 'BOB',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(SettlementDemoSeeder::class);
    $count = DB::table('seller_settlements')->count();
    expect($count)->toBe(1);

    // Segunda corrida: idempotente, no duplica la liquidación demo.
    $this->seed(SettlementDemoSeeder::class);
    expect(DB::table('seller_settlements')->count())->toBe($count);
});
