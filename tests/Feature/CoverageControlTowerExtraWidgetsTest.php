<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Widgets\FailedDeliveriesTodayWidget;
use App\Filament\Widgets\LicensesDocumentsExpiringWidget;
use App\Filament\Widgets\PlatformStatsWidget;
use App\Filament\Widgets\UnacknowledgedTelemetryAlertsWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\Factory\DeliveryRecordFactory;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\Factories\ShipmentRecordFactory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\AlertFactory;

// Ejercita el render de los widgets de la torre de control que aún no estaban cubiertos: montar cada
// widget corre la definición de table()/getStats() y, con filas reales, los closures de color, badge y
// las filas (rows()) que resuelven texto legible y enlaces. No se caza nada: solo render real.

beforeEach(function (): void {
    // Los widgets resuelven el panel actual para render; en pruebas hay que fijarlo a mano.
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza vacíos los widgets de excepciones y KPIs', function (string $widget): void {
    Livewire::test($widget)->assertOk();
})->with([
    FailedDeliveriesTodayWidget::class,
    UnacknowledgedTelemetryAlertsWidget::class,
    LicensesDocumentsExpiringWidget::class,
    PlatformStatsWidget::class,
]);

it('renderiza las entregas fallidas de hoy con filas reales', function (): void {
    // Una entrega fallida tocada hoy y su pedido, para que rows() arme la fila legible con el cliente.
    $shipment = ShipmentRecordFactory::new()->create();
    DeliveryRecordFactory::new()->failed()->create(['shipment_id' => $shipment->getKey()]);

    Livewire::test(FailedDeliveriesTodayWidget::class)->assertOk();
});

it('renderiza las alertas de telemetría sin reconocer con filas reales', function (): void {
    // Una alerta abierta (sin acknowledged_at) para que rows() resuelva tipo, severidad y placa.
    AlertFactory::new()->create();

    Livewire::test(UnacknowledgedTelemetryAlertsWidget::class)->assertOk();
});

it('solo muestra los KPIs globales a administradores y supervisores', function (): void {
    expect(PlatformStatsWidget::canView())->toBeTrue();

    $driver = User::factory()->create();
    $driver->assignRole(Role::Chofer);
    $this->actingAs($driver);
    expect(PlatformStatsWidget::canView())->toBeFalse();
});
