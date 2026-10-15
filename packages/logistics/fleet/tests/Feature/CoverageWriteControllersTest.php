<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Logistics\Fleet\Database\Factories\HelperFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de los controllers HTTP de ESCRITURA del contexto fleet (Patrón F): alta de vehículo, chofer,
 * ayudante y documento por la puerta de integraciones, y las acciones de la app interna (cambiar estado de
 * un vehículo, disponibilidad de un ayudante, armar tripulación y registrar el checklist pre-salida). Se
 * omite el middleware de puerta (token de dispositivo) y se autentica un admin, igual que el
 * CoverageHttpIndexTest de lectura; cada endpoint recorre su validación + el comando CQRS detrás.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('registra un vehículo por la puerta de integraciones', function (): void {
    $this->postJson('/api/v1/integrations/fleet/vehicles', [
        'plate' => 'ABC123',
        'capacity_kg' => 8000,
        'capacity_m3' => 32.5,
        'home_base_node_id' => Uuid::generate()->value,
        'status' => 'available',
        'documents' => [
            ['type' => 'soat', 'expires_on' => '2027-01-31'],
        ],
    ])->assertStatus(201)
        ->assertJsonPath('data.plate', 'ABC123');
});

it('registra un chofer por la puerta de integraciones', function (): void {
    $this->postJson('/api/v1/integrations/fleet/drivers', [
        'name' => 'Juan Perez',
        'license_number' => 'LIC-99999',
        'license_expiry' => '2028-06-30',
        'enabled_vehicle_ids' => [Uuid::generate()->value],
        'active' => true,
    ])->assertStatus(201)
        ->assertJsonPath('data.license_number', 'LIC-99999');
});

it('registra un ayudante por la puerta de integraciones', function (): void {
    $this->postJson('/api/v1/integrations/fleet/helpers', [
        'name' => 'Pedro Gomez',
        'phone' => '70123456',
        'active' => true,
        'documents' => [
            ['type' => 'health_card', 'expires_on' => '2027-03-31'],
        ],
    ])->assertStatus(201)
        ->assertJsonPath('data.name', 'Pedro Gomez');
});

it('registra un documento de flota por la puerta de integraciones', function (): void {
    $this->postJson('/api/v1/integrations/fleet/documents', [
        'owner_type' => 'vehicle',
        'owner_id' => Uuid::generate()->value,
        'document_type' => 'soat',
        'expires_on' => '2027-05-31',
        'issued_on' => '2026-05-31',
        'note' => 'Póliza anual',
        'reference' => 'POL-123',
    ])->assertStatus(201)
        ->assertJsonPath('data.document_type', 'soat');
});

it('cambia el estado de un vehículo existente por la app interna', function (): void {
    $vehicle = VehicleFactory::new()->create();

    $this->patchJson('/api/v1/app/fleet/vehicles/'.$vehicle->getKey().'/status', [
        'status' => 'maintenance',
    ])->assertStatus(200)
        ->assertJsonPath('data.new_status', 'maintenance');
});

it('cambia la disponibilidad de un ayudante existente por la app interna', function (): void {
    $helper = HelperFactory::new()->create();

    $this->patchJson('/api/v1/app/fleet/helpers/'.$helper->getKey().'/availability', [
        'active' => false,
    ])->assertStatus(200)
        ->assertJsonPath('data.active', false);
});

it('arma una tripulación del día por la app interna', function (): void {
    $this->postJson('/api/v1/app/fleet/crews', [
        'driver_id' => Uuid::generate()->value,
        'helper_ids' => [Uuid::generate()->value],
        'vehicle_id' => Uuid::generate()->value,
        'assembled_for' => '2026-10-15',
    ])->assertStatus(201)
        ->assertJsonPath('data.assembled_for', '2026-10-15');
});

it('registra el checklist pre-salida por la app interna', function (): void {
    $this->postJson('/api/v1/app/fleet/pre-trip-checklists', [
        'vehicle_id' => Uuid::generate()->value,
        'driver_id' => Uuid::generate()->value,
        'items' => [
            'brakes' => true,
            'lights' => true,
            'tires' => false,
        ],
        'performed_on' => '2026-10-15',
        'notes' => 'Observaciones de patio',
    ])->assertStatus(201);
});

it('rechaza con 422 un vehículo sin placa', function (): void {
    $this->postJson('/api/v1/integrations/fleet/vehicles', [
        'capacity_kg' => 8000,
    ])->assertStatus(422);
});
