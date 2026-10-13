<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Widgets\AutoPlanRunsWidget;
use App\Filament\Widgets\DeliveriesTodayWidget;
use App\Filament\Widgets\NotificationsTodayWidget;
use App\Filament\Widgets\OutboxHealthWidget;
use App\Filament\Widgets\QueueHealthWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

// Verifica los widgets de la torre de control (O-05): solo los ve el alcance operativo global, y
// renderizan leyendo la base por DB directa, tolerando datos vacíos.

beforeEach(function (): void {
    // Los widgets resuelven el panel actual para render; en pruebas hay que fijarlo a mano.
    Filament::setCurrentPanel('admin');
});

/**
 * @return list<class-string>
 */
function controlTowerWidgets(): array
{
    return [
        OutboxHealthWidget::class,
        QueueHealthWidget::class,
        DeliveriesTodayWidget::class,
        NotificationsTodayWidget::class,
        AutoPlanRunsWidget::class,
    ];
}

it('is visible only to admins and supervisors', function (string $widget): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
    expect($widget::canView())->toBeTrue();

    $supervisor = User::factory()->create();
    $supervisor->assignRole(Role::Supervisor);
    $this->actingAs($supervisor);
    expect($widget::canView())->toBeTrue();

    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);
    $this->actingAs($driver);
    expect($widget::canView())->toBeFalse();
})->with(controlTowerWidgets());

it('renders without data (tolerant to empty tables)', function (string $widget): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);

    Livewire::test($widget)->assertOk();
})->with(controlTowerWidgets());

it('reads outbox counts by status from the database', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);

    DB::table('outbox_messages')->insert([
        'id' => (string) Str::uuid7(),
        'event_id' => (string) Str::uuid7(),
        'aggregate_type' => 'Shipment',
        'aggregate_id' => (string) Str::uuid7(),
        'event_name' => 'shipment.created',
        'payload' => json_encode(['x' => 1]),
        'status' => 'dead_lettered',
        'attempts' => 7,
        'available_at' => now(),
        'created_at' => now(),
    ]);

    Livewire::test(OutboxHealthWidget::class)
        ->assertOk()
        ->assertSee('Dead-letter');
});

it('lists recent auto-plan runs from the database', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);

    DB::table('planning_auto_plan_runs')->insert([
        'id' => (string) Str::uuid7(),
        'plan_date' => now()->toDateString(),
        'strategy' => 'balanced',
        'status' => 'completed',
        'requested_at' => now(),
        'completed_at' => now(),
        'trucks_planned' => 4,
        'stops_planned' => 37,
        'unassigned_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(AutoPlanRunsWidget::class)
        ->assertOk()
        ->assertSee('balanced');
});
