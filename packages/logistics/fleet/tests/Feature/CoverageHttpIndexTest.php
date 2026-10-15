<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

/**
 * Cobertura de los controllers HTTP de lectura de la puerta /api/v1/app/fleet/*: cada índice ejecuta su
 * handler de consulta, el ReadModel y el mapper de lectura. Se omite el middleware de la puerta (token de
 * dispositivo) igual que el CoverageHttpLifecycleTest de execution, y se autentica un usuario admin.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('lista por sus endpoints de solo lectura sin reventar con tablas vacías', function (string $routeName): void {
    $this->getJson(route($routeName))
        ->assertOk()
        ->assertJsonStructure(['data']);
})->with([
    'vehicles' => ['api.v1.app.fleet.vehicles.index'],
    'drivers' => ['api.v1.app.fleet.drivers.index'],
    'helpers' => ['api.v1.app.fleet.helpers.index'],
    'documents' => ['api.v1.app.fleet.documents.index'],
    'pre-trip-checklists' => ['api.v1.app.fleet.pre-trip-checklists.index'],
    // expiring-documents exige parámetro de días (422 sin él); su handler ya lo cubre CoverageQueriesTest.
]);
