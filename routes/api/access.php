<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Access\Presentation\Http\Controller\AssignRoleToUserController;
use Logistics\Access\Presentation\Http\Controller\ChangeUserPasswordController;
use Logistics\Access\Presentation\Http\Controller\CreatePermissionController;
use Logistics\Access\Presentation\Http\Controller\CreateRoleController;
use Logistics\Access\Presentation\Http\Controller\CreateUserController;
use Logistics\Access\Presentation\Http\Controller\DeleteRoleController;
use Logistics\Access\Presentation\Http\Controller\GetIntegrationTokenController;
use Logistics\Access\Presentation\Http\Controller\GetRoleController;
use Logistics\Access\Presentation\Http\Controller\GetUserController;
use Logistics\Access\Presentation\Http\Controller\GrantPermissionToRoleController;
use Logistics\Access\Presentation\Http\Controller\IssueIntegrationTokenController;
use Logistics\Access\Presentation\Http\Controller\ListIntegrationTokensController;
use Logistics\Access\Presentation\Http\Controller\ListPermissionsController;
use Logistics\Access\Presentation\Http\Controller\ListRolesController;
use Logistics\Access\Presentation\Http\Controller\ListUsersController;
use Logistics\Access\Presentation\Http\Controller\ReactivateUserController;
use Logistics\Access\Presentation\Http\Controller\RemoveRoleFromUserController;
use Logistics\Access\Presentation\Http\Controller\RenameRoleController;
use Logistics\Access\Presentation\Http\Controller\RevokeIntegrationTokenController;
use Logistics\Access\Presentation\Http\Controller\RevokePermissionFromRoleController;
use Logistics\Access\Presentation\Http\Controller\RotateIntegrationTokenController;
use Logistics\Access\Presentation\Http\Controller\SetRolePermissionsController;
use Logistics\Access\Presentation\Http\Controller\SetTokenPermissionsController;
use Logistics\Access\Presentation\Http\Controller\SuspendUserController;
use Logistics\Access\Presentation\Http\Controller\VerifyIntegrationTokenController;

/*
| Fragmento de rutas del contexto Access (identidad, RBAC y tokens de integración).
|
| Devuelve un mapa puerta => closure; el integrador (routes/api.php) invoca cada closure dentro del
| grupo de su puerta (prefijo + middleware los pone el orquestador). Rutas relativas a la puerta.
|
| Autorización: la gestión vive bajo la puerta `app`, que YA aplica auth:sanctum + device. No se añade
| `access.permission:<key>` a estas rutas porque ese guardia solo resuelve permisos de TOKEN de
| integración (los deja AuthenticateIntegrationToken en la request), no de usuarios Sanctum; para el
| usuario interno la autorización la resuelve el middleware de puerta heredado. La verificación de
| credencial del integrador vive en la puerta `integrations`, protegida por el alias `access.token`.
*/

return [
    // Puerta app: gestión interna de RBAC, usuarios y tokens (torre de control / administración).
    'app' => static function (): void {
        Route::prefix('access')->name('access.')->group(function (): void {
            // Catálogo de permisos: listar (filtro opcional ?group=) y crear.
            Route::get('permissions', ListPermissionsController::class)->name('permissions.index');
            Route::post('permissions', CreatePermissionController::class)->name('permissions.store');

            // Roles: listado, alta, detalle, renombre, borrado.
            Route::get('roles', ListRolesController::class)->name('roles.index');
            Route::post('roles', CreateRoleController::class)->name('roles.store');
            Route::get('roles/{roleId}', GetRoleController::class)->name('roles.show');
            Route::patch('roles/{roleId}', RenameRoleController::class)->name('roles.rename');
            Route::delete('roles/{roleId}', DeleteRoleController::class)->name('roles.destroy');

            // Permisos de un rol: reemplazar el conjunto completo, otorgar o revocar uno.
            Route::put('roles/{roleId}/permissions', SetRolePermissionsController::class)
                ->name('roles.permissions.set');
            Route::post('roles/{roleId}/permissions/grant', GrantPermissionToRoleController::class)
                ->name('roles.permissions.grant');
            Route::post('roles/{roleId}/permissions/revoke', RevokePermissionFromRoleController::class)
                ->name('roles.permissions.revoke');

            // Usuarios: listado (filtro opcional ?status=), alta y detalle.
            Route::get('users', ListUsersController::class)->name('users.index');
            Route::post('users', CreateUserController::class)->name('users.store');
            Route::get('users/{userId}', GetUserController::class)->name('users.show');

            // Ciclo de vida del usuario: suspender / reactivar y cambiar contraseña.
            Route::post('users/{userId}/suspend', SuspendUserController::class)->name('users.suspend');
            Route::post('users/{userId}/reactivate', ReactivateUserController::class)->name('users.reactivate');
            Route::post('users/{userId}/password', ChangeUserPasswordController::class)->name('users.password');

            // Roles de un usuario: asignar (cuerpo) y quitar (por clave en la ruta).
            Route::post('users/{userId}/roles', AssignRoleToUserController::class)->name('users.roles.assign');
            Route::delete('users/{userId}/roles/{roleKey}', RemoveRoleFromUserController::class)
                ->name('users.roles.remove');

            // Tokens de integración: listado (filtro opcional ?status=) y detalle (nunca el secreto).
            Route::get('tokens', ListIntegrationTokensController::class)->name('tokens.index');
            Route::get('tokens/{tokenId}', GetIntegrationTokenController::class)->name('tokens.show');

            // Emisión y rotación revelan el secreto en claro UNA sola vez (201); revocar y set de permisos.
            Route::post('tokens', IssueIntegrationTokenController::class)->name('tokens.issue');
            Route::post('tokens/{tokenId}/rotate', RotateIntegrationTokenController::class)->name('tokens.rotate');
            Route::post('tokens/{tokenId}/revoke', RevokeIntegrationTokenController::class)->name('tokens.revoke');
            Route::put('tokens/{tokenId}/permissions', SetTokenPermissionsController::class)
                ->name('tokens.permissions.set');
        });
    },

    // Puerta integrations: el integrador valida su propia credencial (token_id + permisos efectivos).
    // Protegida por el alias `access.token`, que autentica el Bearer y deja los datos en la request.
    'integrations' => static function (): void {
        Route::prefix('access')->name('access.')->group(function (): void {
            Route::get('token/verify', VerifyIntegrationTokenController::class)
                ->middleware('access.token')
                ->name('token.verify');
        });
    },
];
