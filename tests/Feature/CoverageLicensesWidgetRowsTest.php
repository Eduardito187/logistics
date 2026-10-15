<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Widgets\LicensesDocumentsExpiringWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\FleetDocumentFactory;
use Logistics\Fleet\Database\Factories\HelperFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;

/**
 * Cobertura de la tabla del widget de licencias y documentos por vencer: el render vacío ya está cubierto
 * en otra suite; aquí se siembran vencimientos reales (chofer, ayudante, vehículo y documento suelto) en
 * los tres umbrales (vencido, ≤7 días, ≤30 días) para ejercitar rows(), el ordenamiento por urgencia, los
 * closures de color/badge y la resolución de etiqueta y URL del dueño.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza el widget con documentos en los tres umbrales de vencimiento', function (): void {
    // Chofer con licencia VENCIDA.
    DriverFactory::new()->create(['license_expiry' => now()->subDays(3)->toDateString()]);
    // Chofer con licencia por vencer ≤ 7 días.
    DriverFactory::new()->create(['license_expiry' => now()->addDays(5)->toDateString()]);
    // Chofer con licencia por vencer ≤ 30 días.
    DriverFactory::new()->create(['license_expiry' => now()->addDays(20)->toDateString()]);

    // Vehículo con un documento embebido por vencer (SOAT) → camino forVehicle + URL al recurso.
    VehicleFactory::new()->create([
        'documents' => [
            ['type' => 'soat', 'expires_on' => now()->addDays(10)->toDateString()],
        ],
    ]);

    // Ayudante con un documento por vencer → camino forHelper.
    HelperFactory::new()->create([
        'documents' => [
            ['type' => 'health_card', 'expires_on' => now()->addDays(4)->toDateString()],
        ],
    ]);

    // Documento suelto de flota con un tipo libre no previsto → fallback de documentTypeLabel.
    FleetDocumentFactory::new()->create([
        'owner_type' => 'vehicle',
        'document_type' => 'tipo_libre_no_previsto',
        'expires_on' => now()->addDays(2)->toDateString(),
    ]);

    Livewire::test(LicensesDocumentsExpiringWidget::class)->assertOk();
});
