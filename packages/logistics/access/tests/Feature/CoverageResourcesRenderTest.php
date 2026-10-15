<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\AuditEntryFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\IntegrationTokenFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\PermissionFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\RoleFactory;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Factory\UserFactory;
use Logistics\Access\Presentation\Filament\Resources\AuditLogResource\Pages\ListAuditEntries;
use Logistics\Access\Presentation\Filament\Resources\AuditLogResource\Pages\ViewAuditEntry;
use Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource\Pages\CreateIntegrationToken;
use Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource\Pages\ListIntegrationTokens;
use Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource\Pages\ViewIntegrationToken;
use Logistics\Access\Presentation\Filament\Resources\PermissionResource\Pages\CreatePermission;
use Logistics\Access\Presentation\Filament\Resources\PermissionResource\Pages\ListPermissions;
use Logistics\Access\Presentation\Filament\Resources\PermissionResource\Pages\ViewPermission;
use Logistics\Access\Presentation\Filament\Resources\RoleResource\Pages\CreateRole;
use Logistics\Access\Presentation\Filament\Resources\RoleResource\Pages\ListRoles;
use Logistics\Access\Presentation\Filament\Resources\RoleResource\Pages\ViewRole;
use Logistics\Access\Presentation\Filament\Resources\UserResource\Pages\CreateUser;
use Logistics\Access\Presentation\Filament\Resources\UserResource\Pages\ListUsers;
use Logistics\Access\Presentation\Filament\Resources\UserResource\Pages\ViewUser;

/**
 * Cobertura de las Resources/Pages de Filament del contexto Access.
 *
 * Montar cada página Livewire ejecuta las definiciones de table()/form()/schema()/infolist() del recurso,
 * que es donde vive la mayor parte del código de Presentation sin cubrir. List y Create no necesitan datos;
 * las páginas View sí: se siembra una fila con la fábrica del contexto. Corre contra PostgreSQL
 * (logistics_test); se marca skip sin PostgreSQL para no romper la suite Unit.
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

it('renderiza el listado de usuarios', function (): void {
    Livewire::test(ListUsers::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el create de usuario', function (): void {
    Livewire::test(CreateUser::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza la vista de un usuario', function (): void {
    $record = UserFactory::new()->create();
    Livewire::test(ViewUser::class, ['record' => $record->getKey()])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el listado de roles', function (): void {
    Livewire::test(ListRoles::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el create de rol', function (): void {
    Livewire::test(CreateRole::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza la vista de un rol', function (): void {
    $record = RoleFactory::new()->create();
    Livewire::test(ViewRole::class, ['record' => $record->getKey()])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el listado de permisos', function (): void {
    Livewire::test(ListPermissions::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el create de permiso', function (): void {
    Livewire::test(CreatePermission::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza la vista de un permiso', function (): void {
    $record = PermissionFactory::new()->create();
    Livewire::test(ViewPermission::class, ['record' => $record->getKey()])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el listado de tokens de integración', function (): void {
    Livewire::test(ListIntegrationTokens::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el create de token de integración', function (): void {
    Livewire::test(CreateIntegrationToken::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza la vista de un token de integración', function (): void {
    $record = IntegrationTokenFactory::new()->create();
    Livewire::test(ViewIntegrationToken::class, ['record' => $record->getKey()])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza el listado de la bitácora de auditoría', function (): void {
    Livewire::test(ListAuditEntries::class)->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('renderiza la vista de una entrada de auditoría', function (): void {
    $record = AuditEntryFactory::new()->create();
    Livewire::test(ViewAuditEntry::class, ['record' => $record->getKey()])->assertOk();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
