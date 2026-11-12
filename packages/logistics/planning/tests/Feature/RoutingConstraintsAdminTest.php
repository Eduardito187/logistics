<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Planning\Application\Command\RemoveShipmentRequirement\RemoveShipmentRequirement;
use Logistics\Planning\Application\Command\RemoveVehicleRestrictions\RemoveVehicleRestrictions;
use Logistics\Planning\Application\Command\SetShipmentRequirement\SetShipmentRequirement;
use Logistics\Planning\Application\Command\SetVehicleRestrictions\SetVehicleRestrictions;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;
use Logistics\Planning\Infrastructure\Adapter\FleetVehicleAvailabilityProvider;
use Logistics\Planning\Presentation\Filament\PlanResource\Pages\ListPlans;
use Logistics\Planning\Presentation\Filament\ShipmentRequirementResource;
use Logistics\Planning\Presentation\Filament\ShipmentRequirementResource\Pages\CreateShipmentRequirement;
use Logistics\Planning\Presentation\Filament\ShipmentRequirementResource\Pages\EditShipmentRequirement;
use Logistics\Planning\Presentation\Filament\ShipmentRequirementResource\Pages\ListShipmentRequirements;
use Logistics\Planning\Presentation\Filament\VehicleRestrictionResource;
use Logistics\Planning\Presentation\Filament\VehicleRestrictionResource\Pages\CreateVehicleRestriction;
use Logistics\Planning\Presentation\Filament\VehicleRestrictionResource\Pages\EditVehicleRestriction;
use Logistics\Planning\Presentation\Filament\VehicleRestrictionResource\Pages\ListVehicleRestrictions;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;

// Ola 0 "restricciones sin dueño": los comandos que escriben planning_vehicle_restrictions /
// planning_shipment_requirements (upsert por id, validados por los value objects) y el render de los
// resources del admin que los despachan.

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('SetVehicleRestrictions crea y reemplaza el perfil del camión, y el planificador lo lee', function (): void {
    $vehicle = VehicleFactory::new()->create(['status' => 'available']);
    $vehicleId = (string) $vehicle->getKey();
    $bus = app(CommandBus::class);

    $bus->dispatch(new SetVehicleRestrictions($vehicleId, allowedLoadTypes: ['fragile'], maxHeightM: 2.5));
    $bus->dispatch(new SetVehicleRestrictions($vehicleId, allowedLoadTypes: ['fragile', 'cold'], forbiddenZones: ['centro']));

    $rows = DB::table('planning_vehicle_restrictions')->where('vehicle_id', $vehicleId)->get();
    expect($rows)->toHaveCount(1)
        ->and(json_decode((string) $rows[0]->allowed_load_types, true))->toBe(['fragile', 'cold'])
        ->and($rows[0]->max_height_m)->toBeNull();

    $specs = app(FleetVehicleAvailabilityProvider::class)->availableFor(PlanDate::fromString('2026-10-20'));
    $mine = array_values(array_filter($specs, static fn (VehicleCapacitySpec $spec): bool => $spec->vehicleId === $vehicleId));
    expect($mine)->toHaveCount(1)
        ->and($mine[0]->profile->forbiddenZones)->toBe(['centro']);

    $bus->dispatch(new RemoveVehicleRestrictions($vehicleId));
    expect(DB::table('planning_vehicle_restrictions')->where('vehicle_id', $vehicleId)->count())->toBe(0);
});

it('SetVehicleRestrictions rechaza un límite físico no positivo', function (): void {
    $vehicle = VehicleFactory::new()->create();

    app(CommandBus::class)->dispatch(new SetVehicleRestrictions((string) $vehicle->getKey(), maxWeightKg: 0.0));
})->throws(PlanningException::class);

it('SetShipmentRequirement guarda, reemplaza y quita los requisitos del envío', function (): void {
    $shipment = ShipmentRecordFactory::new()->create();
    $shipmentId = (string) $shipment->getKey();
    $bus = app(CommandBus::class);

    $bus->dispatch(new SetShipmentRequirement($shipmentId, loadType: 'fragile', heightM: 1.2));
    $bus->dispatch(new SetShipmentRequirement($shipmentId, loadType: 'cold', zone: 'norte'));

    $rows = DB::table('planning_shipment_requirements')->where('shipment_id', $shipmentId)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->load_type)->toBe('cold')
        ->and($rows[0]->zone)->toBe('norte')
        ->and($rows[0]->height_m)->toBeNull();

    $bus->dispatch(new RemoveShipmentRequirement($shipmentId));
    expect(DB::table('planning_shipment_requirements')->where('shipment_id', $shipmentId)->count())->toBe(0);
});

it('renderiza los listados de restricciones vacíos y con filas', function (): void {
    Livewire::test(ListVehicleRestrictions::class)->assertOk();
    Livewire::test(ListShipmentRequirements::class)->assertOk();

    $vehicle = VehicleFactory::new()->create();
    $shipment = ShipmentRecordFactory::new()->create();
    $bus = app(CommandBus::class);
    $bus->dispatch(new SetVehicleRestrictions((string) $vehicle->getKey(), allowedLoadTypes: ['fragile'], allowedZones: ['norte']));
    $bus->dispatch(new SetShipmentRequirement((string) $shipment->getKey(), loadType: 'fragile', weightKg: 30.0));

    Livewire::test(ListVehicleRestrictions::class)
        ->assertOk()
        ->assertSee((string) $vehicle->plate);
    Livewire::test(ListShipmentRequirements::class)
        ->assertOk()
        ->assertSee((string) $shipment->customer_name);
});

it('renderiza las páginas de alta y edición de restricciones y requisitos', function (): void {
    $vehicle = VehicleFactory::new()->create();
    $shipment = ShipmentRecordFactory::new()->create();
    $bus = app(CommandBus::class);
    $bus->dispatch(new SetVehicleRestrictions((string) $vehicle->getKey(), maxHeightM: 3.0));
    $bus->dispatch(new SetShipmentRequirement((string) $shipment->getKey(), zone: 'sur'));

    Livewire::test(CreateVehicleRestriction::class)->assertOk();
    Livewire::test(CreateShipmentRequirement::class)->assertOk();
    Livewire::test(EditVehicleRestriction::class, ['record' => (string) $vehicle->getKey()])->assertOk();
    Livewire::test(EditShipmentRequirement::class, ['record' => (string) $shipment->getKey()])->assertOk();
});

it('los selects buscan camiones por placa y envíos por referencia, sin mostrar UUIDs', function (): void {
    $vehicle = VehicleFactory::new()->create(['plate' => '7788ABC']);
    $shipment = ShipmentRecordFactory::new()->create(['order_reference' => 'ORD-998877', 'customer_name' => 'Ana Rojas']);

    expect(VehicleRestrictionResource::vehicleSearch('7788'))->toBe([(string) $vehicle->getKey() => '7788ABC'])
        ->and(ShipmentRequirementResource::shipmentSearch('998877'))->toBe([(string) $shipment->getKey() => 'ORD-998877 — Ana Rojas']);
});

it('la lista de planes enlaza a restricciones y requisitos', function (): void {
    Livewire::test(ListPlans::class)
        ->assertOk()
        ->assertActionExists('truckRestrictions')
        ->assertActionExists('shipmentRequirements');
});
