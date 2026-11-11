<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

/**
 * /admin/users/{record} es la ficha IAM (access_users, id UUID). Un id numérico (cuenta del backoffice, que el
 * directorio de Personal enlazaba por error) daba 500 de PostgreSQL; ahora es «no encontrado», nunca 500.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        $this->markTestSkipped('Requiere PostgreSQL (base logistics_test).');
    }
});

it('un id que no es UUID en la ficha de usuario no da 500', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);

    $response = $this->actingAs($admin)->get('/admin/users/1');

    expect($response->getStatusCode())->toBeIn([302, 404]);
});

it('el directorio de Personal no enlaza cuentas del backoffice a /admin/users/{numérico}', function (): void {
    $admin = User::factory()->create(['name' => 'Ana Directorio']);
    $admin->assignRole(Role::Admin);

    $html = $this->actingAs($admin)->get('/admin/personnel-directory')->assertOk()->getContent();

    expect($html)->toContain('Ana Directorio')
        ->and(preg_match('#/admin/users/\d+["\'?]#', (string) $html))->toBe(0);
});
