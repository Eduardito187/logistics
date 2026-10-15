<?php

declare(strict_types=1);

use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\IntegrationTokenFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\RoleFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\UserFactory;

/**
 * Cobertura de los controllers HTTP del contexto Access por sus rutas reales de la puerta `app`
 * (/api/v1/app/access/...). Se desactiva el middleware de puerta (auth/device) para ejercitar el
 * controller, su FormRequest y el bus CQRS; el detalle de autenticación por token vive en otros tests.
 * Corre contra PostgreSQL (logistics_test); skip sin él.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('crea un usuario (202)', function (): void {
    $this->postJson('/api/v1/app/access/users', [
        'name' => 'Ada Lovelace',
        'email' => 'ada.cov@dismac.test',
        'password' => 'password123',
        'role_keys' => [],
        'status' => 'active',
    ])->assertStatus(202)
        ->assertJsonPath('data.accepted', true);
});

it('crea un rol (202)', function (): void {
    $this->postJson('/api/v1/app/access/roles', [
        'key' => 'cov-role-'.uniqid(),
        'name' => 'Coverage Role',
        'description' => 'Rol de cobertura',
        'permission_keys' => [],
    ])->assertStatus(202)
        ->assertJsonPath('data.accepted', true);
});

it('registra un permiso (202)', function (): void {
    $this->postJson('/api/v1/app/access/permissions', [
        'key' => 'coverage.read',
        'description' => 'Permiso de cobertura',
    ])->assertStatus(202);
});

it('otorga y revoca un permiso de un rol (202)', function (): void {
    $role = RoleFactory::new()->create();

    $this->postJson("/api/v1/app/access/roles/{$role->getKey()}/permissions/grant", [
        'permission_key' => 'shipments.read',
    ])->assertStatus(202);

    $this->postJson("/api/v1/app/access/roles/{$role->getKey()}/permissions/revoke", [
        'permission_key' => 'shipments.read',
    ])->assertStatus(202);
});

it('reemplaza el conjunto de permisos de un rol (202)', function (): void {
    $role = RoleFactory::new()->create();

    $this->putJson("/api/v1/app/access/roles/{$role->getKey()}/permissions", [
        'permission_keys' => ['shipments.read', 'fleet.read'],
    ])->assertStatus(202);
});

it('renombra un rol (202)', function (): void {
    $role = RoleFactory::new()->create();

    $this->patchJson("/api/v1/app/access/roles/{$role->getKey()}", [
        'name' => 'Renombrado',
        'description' => 'Nueva descripción',
    ])->assertStatus(202);
});

it('asigna y quita un rol a un usuario (202)', function (): void {
    $user = UserFactory::new()->create();

    $this->postJson("/api/v1/app/access/users/{$user->getKey()}/roles", [
        'role_key' => 'picker',
    ])->assertStatus(202);

    $this->deleteJson("/api/v1/app/access/users/{$user->getKey()}/roles/picker")
        ->assertStatus(202);
});

it('reemplaza el conjunto de permisos de un token (202)', function (): void {
    $token = IntegrationTokenFactory::new()->create();

    $this->putJson("/api/v1/app/access/tokens/{$token->getKey()}/permissions", [
        'permission_keys' => ['shipments.read'],
    ])->assertStatus(202);
});

it('emite un token de integración y revela el secreto una vez (201)', function (): void {
    $this->postJson('/api/v1/app/access/tokens', [
        'name' => 'ERP Coverage',
        'permission_keys' => ['shipments.read', 'fleet.read'],
    ])->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'plain_token']]);
});

it('rechaza un cuerpo inválido con 422', function (): void {
    $this->postJson('/api/v1/app/access/users', [
        'name' => '',
        'email' => 'no-es-email',
    ])->assertStatus(422);
});

it('lista usuarios, roles, permisos y tokens (200)', function (): void {
    UserFactory::new()->count(2)->create();
    RoleFactory::new()->count(2)->create();
    IntegrationTokenFactory::new()->count(2)->create();

    $this->getJson('/api/v1/app/access/users')->assertOk()->assertJsonStructure(['data']);
    $this->getJson('/api/v1/app/access/users?status=active')->assertOk();
    $this->getJson('/api/v1/app/access/roles')->assertOk()->assertJsonStructure(['data']);
    $this->getJson('/api/v1/app/access/permissions')->assertOk()->assertJsonStructure(['data']);
    $this->getJson('/api/v1/app/access/permissions?group=shipments')->assertOk();
    $this->getJson('/api/v1/app/access/tokens')->assertOk()->assertJsonStructure(['data']);
    $this->getJson('/api/v1/app/access/tokens?status=active')->assertOk();
});
