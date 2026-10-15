<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages\CreatePhysicalUnit;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages\EditPhysicalUnit;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages\ListPhysicalUnits;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages\ViewPhysicalUnit;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages\CreateShipmentLine;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages\EditShipmentLine;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages\ListShipmentLines;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages\ViewShipmentLine;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages\EditShipment;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages\ListShipments;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\RelationManagers\LinesRelationManager;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\RelationManagers\UnitsRelationManager;

/**
 * Cobertura de los recursos Filament del contexto shipments (Patrón A del brief): montar cada página
 * Livewire ejecuta las definiciones de table()/form()/schema()/infolist() de las Resources y sus
 * RelationManagers. List y Create no requieren datos; View/Edit reciben un registro sembrado con la
 * factory del contexto. Se marca skip sin PostgreSQL para no romper la suite Unit.
 */
beforeEach(function (): void {
    // Fuera de HTTP hay que fijar el panel actual a mano para que los recursos resuelvan su contexto.
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el listado de envíos', function (): void {
    Livewire::test(ListShipments::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza la vista y edición de un envío', function (): void {
    $shipment = ShipmentRecord::factory()->create();

    Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])->assertOk();
    Livewire::test(EditShipment::class, ['record' => $shipment->getKey()])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza los relation managers de líneas y bultos del envío', function (): void {
    $shipment = ShipmentRecord::factory()->create();
    ShipmentLineRecord::factory()->create(['shipment_id' => $shipment->getKey()]);
    PhysicalUnitRecord::factory()->create(['shipment_id' => $shipment->getKey()]);

    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $shipment,
        'pageClass' => ViewShipment::class,
    ])->assertOk();

    Livewire::test(UnitsRelationManager::class, [
        'ownerRecord' => $shipment,
        'pageClass' => ViewShipment::class,
    ])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el CRUD de líneas de envío', function (): void {
    $line = ShipmentLineRecord::factory()->create();

    Livewire::test(ListShipmentLines::class)->assertOk();
    Livewire::test(CreateShipmentLine::class)->assertOk();
    Livewire::test(ViewShipmentLine::class, ['record' => $line->getKey()])->assertOk();
    Livewire::test(EditShipmentLine::class, ['record' => $line->getKey()])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el CRUD de bultos (unidades físicas)', function (): void {
    $unit = PhysicalUnitRecord::factory()->create();

    Livewire::test(ListPhysicalUnits::class)->assertOk();
    Livewire::test(CreatePhysicalUnit::class)->assertOk();
    Livewire::test(ViewPhysicalUnit::class, ['record' => $unit->getKey()])->assertOk();
    Livewire::test(EditPhysicalUnit::class, ['record' => $unit->getKey()])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
