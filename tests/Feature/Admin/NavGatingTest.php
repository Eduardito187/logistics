<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Pages\ControlTower;
use App\Models\User;
use Filament\Facades\Filament;
use Logistics\Access\Presentation\Filament\Resources\UserResource;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource;
use Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource;
use Logistics\Execution\Presentation\Filament\Resources\ScanEventResource;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Settings\Presentation\Filament\Resources\IntegrationResource;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;
use Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource;

// Gating de navegación del admin por rol (el admin es el único frontend: cada rol ve solo lo suyo).
// Afirma canAccess() de Resources y Pages para una muestra representativa de roles y secciones.

beforeEach(function (): void {
    // canAccess de algunos componentes resuelve el panel actual; en pruebas se fija a mano.
    Filament::setCurrentPanel('admin');
});

/**
 * Crea una cuenta con el rol dado (sin autenticar; el caller usa $this->actingAs()).
 */
function userWithRole(Role $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('lets the administrator reach every tier', function (): void {
    $this->actingAs(userWithRole(Role::Admin));

    // Administration (solo admin), oficina y campo: todo visible para el administrador.
    expect(UserResource::canAccess())->toBeTrue()
        ->and(IntegrationResource::canAccess())->toBeTrue()
        ->and(ShipmentResource::canAccess())->toBeTrue()
        ->and(VehicleResource::canAccess())->toBeTrue()
        ->and(DeliveryResource::canAccess())->toBeTrue()
        ->and(ControlTower::canAccess())->toBeTrue();
});

it('gives the supervisor office sections but never Administration', function (): void {
    $this->actingAs(userWithRole(Role::Supervisor));

    // Oficina: sí. Administration (seguridad/config): no, ni siquiera un supervisor.
    expect(ShipmentResource::canAccess())->toBeTrue()
        ->and(VehicleResource::canAccess())->toBeTrue()
        ->and(DeliveryResource::canAccess())->toBeTrue()
        ->and(ControlTower::canAccess())->toBeTrue()
        ->and(UserResource::canAccess())->toBeFalse()
        ->and(IntegrationResource::canAccess())->toBeFalse();
});

it('limits the driver to its field operations', function (): void {
    $this->actingAs(userWithRole(Role::Chofer));

    // Campo mínimo: entregas sí; comercio/flota/administración no. La torre de control es el landing.
    expect(DeliveryResource::canAccess())->toBeTrue()
        ->and(ControlTower::canAccess())->toBeTrue()
        ->and(ShipmentResource::canAccess())->toBeFalse()
        ->and(VehicleResource::canAccess())->toBeFalse()
        ->and(UserResource::canAccess())->toBeFalse()
        ->and(NodeReceptionResource::canAccess())->toBeFalse();
});

it('limits the store to reception and tracking', function (): void {
    $this->actingAs(userWithRole(Role::Tienda));

    expect(NodeReceptionResource::canAccess())->toBeTrue()
        ->and(ShipmentTrackingResource::canAccess())->toBeTrue()
        ->and(DeliveryResource::canAccess())->toBeFalse()
        ->and(ScanEventResource::canAccess())->toBeFalse()
        ->and(UserResource::canAccess())->toBeFalse();
});

it('limits the carrier to shipment tracking', function (): void {
    $this->actingAs(userWithRole(Role::Transportista));

    expect(ShipmentTrackingResource::canAccess())->toBeTrue()
        ->and(DeliveryResource::canAccess())->toBeFalse()
        ->and(ShipmentResource::canAccess())->toBeFalse()
        ->and(UserResource::canAccess())->toBeFalse();
});

it('limits the picker to preparation scans', function (): void {
    $this->actingAs(userWithRole(Role::Preparador));

    expect(ScanEventResource::canAccess())->toBeTrue()
        ->and(DeliveryResource::canAccess())->toBeFalse()
        ->and(ShipmentTrackingResource::canAccess())->toBeFalse()
        ->and(UserResource::canAccess())->toBeFalse();
});

it('keeps a role-less account out of every section', function (): void {
    $this->actingAs(User::factory()->create());

    expect(ControlTower::canAccess())->toBeTrue() // puede entrar al panel pero sin secciones
        ->and(ShipmentResource::canAccess())->toBeFalse()
        ->and(DeliveryResource::canAccess())->toBeFalse()
        ->and(UserResource::canAccess())->toBeFalse();
});
