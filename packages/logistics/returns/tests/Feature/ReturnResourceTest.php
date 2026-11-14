<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Logistics\Returns\Application\Command\DispositionReturn\DispositionReturn;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Model\ReturnRecord;
use Logistics\Returns\Presentation\Filament\Resources\ReturnResource;
use Logistics\Returns\Presentation\Filament\Resources\ReturnResource\Pages\ListReturns;
use Logistics\Returns\Presentation\Filament\Resources\ReturnResource\Pages\ViewReturn;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura del admin Filament de devoluciones (ReturnResource + páginas ListReturns y ViewReturn). Montar
 * las páginas Livewire ejecuta table()/infolist()/getTabs(), las columnas calculadas (seller legible, tipo,
 * estado con color, disposición) y las acciones del ciclo (recibir, disponer), que despachan por el
 * CommandBus. El form de una acción Filament v4 no es driveable por callAction(data:): se monta para cubrir
 * su schema y el efecto se verifica con la acción sin form y con el estado en la base. Skip sin stack booteado.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

/**
 * Crea una devolución con una línea real para los renders y las acciones.
 */
function returnResourceSeed(string $status = 'initiated', ?string $ownerPartyId = null, string $mode = 'owned', ?string $disposition = null): ReturnRecord
{
    $record = ReturnRecord::factory()->create([
        'status' => $status,
        'owner_party_id' => $ownerPartyId,
        'fulfillment_mode' => $mode,
        'disposition' => $disposition,
        'warehouse_id' => Uuid::generate()->value,
    ]);

    $record->lines()->create([
        'id' => Uuid::generate()->value,
        'sku' => 'BEL-TV-55',
        'quantity' => 3,
        'reason' => 'damaged',
    ]);

    return $record;
}

it('renderiza el listado con devoluciones en todos los estados y sus segmentos', function (): void {
    // Seller real para ejercitar la rama de nombre legal de sellerName().
    $sellerId = Uuid::generate()->value;
    DB::table('sellers')->insert([
        'id' => $sellerId,
        'legal_name' => 'Marketplace Seller SRL',
        'tax_id' => 'TAX-'.strtoupper(substr(str_replace('-', '', $sellerId), -6)),
        'status' => 'active',
        'contact_name' => 'Contacto',
        'contact_email' => 'seller@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    returnResourceSeed('initiated');
    returnResourceSeed('received', $sellerId, 'fbd');
    returnResourceSeed('dispositioned', $sellerId, 'fbd', 'restock');
    returnResourceSeed('closed', null, 'owned', 'scrap');
    // Dueño sin fila en sellers → rama "Unknown seller".
    returnResourceSeed('initiated', Uuid::generate()->value, 'fbd');

    Livewire::test(ListReturns::class)->assertOk();
});

it('expone un segmento por estado con su conteo', function (): void {
    returnResourceSeed('initiated');
    returnResourceSeed('received');

    $tabs = Livewire::test(ListReturns::class)->instance()->getTabs();

    // Todas + un segmento por cada estado del ciclo.
    expect($tabs)->toHaveKey('all')
        ->and($tabs)->toHaveKey('initiated')
        ->and($tabs)->toHaveKey('received')
        ->and($tabs)->toHaveKey('dispositioned')
        ->and($tabs)->toHaveKey('closed');
});

it('renderiza la ficha 360 (hub) de una devolución', function (): void {
    $record = returnResourceSeed('received');

    Livewire::test(ViewReturn::class, ['record' => $record->getKey()])->assertOk();
});

it('registra la recepción desde la acción de fila de una devolución iniciada', function (): void {
    $record = returnResourceSeed('initiated');

    Livewire::test(ListReturns::class)
        ->callTableAction('receive', $record->getKey())
        ->assertOk();

    expect(DB::table('returns')->where('id', $record->getKey())->value('status'))->toBe('received');
});

it('monta el form de la acción de disposición y dispone restock por el CommandBus', function (): void {
    $record = returnResourceSeed('received');

    // El schema de la acción se cubre montándola; el form no es driveable en test (callAction(data:) no lo
    // llena), así que el efecto se ejercita despachando el comando real por el bus.
    Livewire::test(ListReturns::class)
        ->mountTableAction('disposition', $record->getKey())
        ->assertOk();

    app(CommandBus::class)->dispatch(new DispositionReturn(
        returnId: (string) $record->getKey(),
        disposition: 'restock',
    ));

    expect(DB::table('returns')->where('id', $record->getKey())->value('disposition'))->toBe('restock');
});

it('expone el mapa de estados del recurso para columnas, filtros y segmentos', function (): void {
    $options = ReturnResource::statusOptions();

    expect($options)->toHaveKey('initiated')
        ->and($options)->toHaveKey('received')
        ->and($options)->toHaveKey('dispositioned')
        ->and($options)->toHaveKey('closed');
});
