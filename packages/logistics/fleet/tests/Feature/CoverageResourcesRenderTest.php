<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Fleet\Database\Factories\CrewFactory;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\FleetDocumentFactory;
use Logistics\Fleet\Database\Factories\HelperFactory;
use Logistics\Fleet\Database\Factories\PreTripInspectionFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Fleet\Presentation\Filament\Pages\DriverHistory;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource\Pages\CreateCrew;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource\Pages\ListCrews;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource\Pages\ViewCrew;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource\Pages\CreateDocument;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource\Pages\ListDocuments;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource\Pages\ViewDocument;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\CreateDriver;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\EditDriver;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\ListDrivers;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages\ViewDriver;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\CreateHelper;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\EditHelper;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\ListHelpers;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages\ViewHelper;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages\CreatePreTripChecklist;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages\ListPreTripChecklists;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages\ViewPreTripChecklist;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\CreateVehicle;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\EditVehicle;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\ListVehicles;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages\ViewVehicle;

/**
 * Cobertura de render de las 6 Resources de Filament del contexto fleet (Patrón A del brief) más la
 * página personalizada de historial del chofer. Montar cada página Livewire ejecuta las definiciones de
 * table()/form()/schema()/infolist() y las acciones de cabecera (OwnerDocuments), que es donde se
 * concentra el código sin cubrir de Presentation.
 *
 * No busca cazar bugs: solo ejercita el código real con datos sembrados por las factories del contexto.
 */
beforeEach(function (): void {
    // Los recursos resuelven el panel actual para render; en pruebas hay que fijarlo a mano.
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

// --- Vehicle ---------------------------------------------------------------------------------------

it('renderiza el listado de vehículos', function (): void {
    VehicleFactory::new()->create();

    Livewire::test(ListVehicles::class)->assertOk();
});

it('renderiza el create de vehículo', function (): void {
    Livewire::test(CreateVehicle::class)->assertOk();
});

it('renderiza el view y el edit de un vehículo', function (): void {
    $vehicle = VehicleFactory::new()->create();

    Livewire::test(ViewVehicle::class, ['record' => $vehicle->getKey()])->assertOk();
    Livewire::test(EditVehicle::class, ['record' => $vehicle->getKey()])->assertOk();
});

// --- Driver ----------------------------------------------------------------------------------------

it('renderiza el listado de choferes', function (): void {
    DriverFactory::new()->create();

    Livewire::test(ListDrivers::class)->assertOk();
});

it('renderiza el create de chofer', function (): void {
    Livewire::test(CreateDriver::class)->assertOk();
});

it('renderiza el view y el edit de un chofer', function (): void {
    $driver = DriverFactory::new()->create();

    Livewire::test(ViewDriver::class, ['record' => $driver->getKey()])->assertOk();
    Livewire::test(EditDriver::class, ['record' => $driver->getKey()])->assertOk();
});

// --- Helper ----------------------------------------------------------------------------------------

it('renderiza el listado de ayudantes', function (): void {
    HelperFactory::new()->create();

    Livewire::test(ListHelpers::class)->assertOk();
});

it('renderiza el create de ayudante', function (): void {
    Livewire::test(CreateHelper::class)->assertOk();
});

it('renderiza el view y el edit de un ayudante', function (): void {
    $helper = HelperFactory::new()->create();

    Livewire::test(ViewHelper::class, ['record' => $helper->getKey()])->assertOk();
    Livewire::test(EditHelper::class, ['record' => $helper->getKey()])->assertOk();
});

// --- Document --------------------------------------------------------------------------------------

it('renderiza el listado de documentos de flota', function (): void {
    FleetDocumentFactory::new()->create();

    Livewire::test(ListDocuments::class)->assertOk();
});

it('renderiza el create de documento de flota', function (): void {
    Livewire::test(CreateDocument::class)->assertOk();
});

it('renderiza el view de un documento de flota', function (): void {
    $document = FleetDocumentFactory::new()->withFile()->create();

    Livewire::test(ViewDocument::class, ['record' => $document->getKey()])->assertOk();
});

// --- Crew ------------------------------------------------------------------------------------------

it('renderiza el listado de tripulaciones', function (): void {
    CrewFactory::new()->create();

    Livewire::test(ListCrews::class)->assertOk();
});

it('renderiza el create de tripulación', function (): void {
    Livewire::test(CreateCrew::class)->assertOk();
});

it('renderiza el view de una tripulación', function (): void {
    $crew = CrewFactory::new()->withShift('2026-10-14 06:00:00', '2026-10-14 14:00:00')->create();

    Livewire::test(ViewCrew::class, ['record' => $crew->getKey()])->assertOk();
});

// --- PreTripChecklist ------------------------------------------------------------------------------

it('renderiza el listado de inspecciones pre-salida', function (): void {
    PreTripInspectionFactory::new()->create();

    Livewire::test(ListPreTripChecklists::class)->assertOk();
});

it('renderiza el create de inspección pre-salida', function (): void {
    Livewire::test(CreatePreTripChecklist::class)->assertOk();
});

it('renderiza el view de una inspección pre-salida', function (): void {
    $checklist = PreTripInspectionFactory::new()->failed()->create();

    Livewire::test(ViewPreTripChecklist::class, ['record' => $checklist->getKey()])->assertOk();
});

// --- Página de historial del chofer ----------------------------------------------------------------

it('renderiza la página de historial sin chofer elegido', function (): void {
    Livewire::test(DriverHistory::class)->assertOk();
});

it('renderiza la página de historial con un chofer elegido', function (): void {
    $driver = DriverFactory::new()->create();

    Livewire::test(DriverHistory::class)
        ->set('driverId', (string) $driver->getKey())
        ->set('from', '2026-10-01')
        ->set('to', '2026-10-31')
        ->assertOk();
});
