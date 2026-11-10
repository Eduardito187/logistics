<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Pages\DispatchTimeline;
use App\Filament\Pages\OrderPipeline;
use App\Filament\Pages\ShipmentPipeline;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

// Render y gating de los tableros "workflows visibles" (DisTrack OS F3): el pipeline de envíos y el de
// pedidos. Montar cada página corre mount() + getViewData() (las columnas del servicio sobre datos reales);
// solo oficina (admin/supervisor) accede, un rol de campo no.

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza el pipeline de envíos', function (): void {
    Livewire::test(ShipmentPipeline::class)->assertOk();
});

it('renderiza el pipeline de pedidos', function (): void {
    Livewire::test(OrderPipeline::class)->assertOk();
});

it('renderiza la línea de tiempo de despacho (Gantt)', function (): void {
    Livewire::test(DispatchTimeline::class)->assertOk();
});

it('gatea los tableros a oficina (admin/supervisor), no a roles de campo', function (): void {
    expect(ShipmentPipeline::canAccess())->toBeTrue()
        ->and(OrderPipeline::canAccess())->toBeTrue()
        ->and(DispatchTimeline::canAccess())->toBeTrue();

    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::Supervisor);
    $this->actingAs($supervisor);
    expect(ShipmentPipeline::canAccess())->toBeTrue()
        ->and(OrderPipeline::canAccess())->toBeTrue()
        ->and(DispatchTimeline::canAccess())->toBeTrue();

    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);
    $this->actingAs($driver);
    expect(ShipmentPipeline::canAccess())->toBeFalse()
        ->and(OrderPipeline::canAccess())->toBeFalse()
        ->and(DispatchTimeline::canAccess())->toBeFalse();
});
