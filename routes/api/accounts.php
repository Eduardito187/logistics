<?php

declare(strict_types=1);

use Commerce\Accounts\Presentation\Http\Controller\AddAddressController;
use Commerce\Accounts\Presentation\Http\Controller\GetAccountByUserController;
use Commerce\Accounts\Presentation\Http\Controller\GetAccountController;
use Commerce\Accounts\Presentation\Http\Controller\RegisterAccountController;
use Commerce\Accounts\Presentation\Http\Controller\RemoveAddressController;
use Illuminate\Support\Facades\Route;

/*
| Fragmento de rutas del contexto commerce/accounts (cuentas de cliente, frente comercial).
|
| Devuelve un mapa puerta => closure; el integrador invoca cada closure dentro del grupo de su puerta
| (prefijo + middleware los pone routes/api.php). Rutas relativas a la puerta. Los controllers invocan
| Application vía CommandBus/QueryBus del contenedor. Contrato en openapi/v1.yaml.
*/

return [
    // Puerta channels — storefront/clientes: autoservicio de la cuenta y sus direcciones.
    'channels' => static function (): void {
        Route::prefix('accounts')->name('accounts.')->group(function (): void {
            // POST /api/v1/channels/accounts — registra una cuenta; 201 con el recurso (re-lectura
            // por usuario) o 202 de acuse si no es recuperable. El id lo genera el handler.
            Route::post('/', RegisterAccountController::class)->name('register');
            // GET /api/v1/channels/accounts/by-user/{userId} — cuenta por usuario de access (404 si no).
            Route::get('by-user/{userId}', GetAccountByUserController::class)->name('by-user');
            // GET /api/v1/channels/accounts/{account} — consulta una cuenta por id (404 si no existe).
            Route::get('{account}', GetAccountController::class)->name('show');
            // POST /api/v1/channels/accounts/{account}/addresses — agrega una dirección (read-after-write).
            Route::post('{account}/addresses', AddAddressController::class)->name('addresses.add');
            // DELETE /api/v1/channels/accounts/{account}/addresses/{address} — quita una dirección por
            // etiqueta ({address} ES la etiqueta: la dirección no tiene id propio en el dominio).
            Route::delete('{account}/addresses/{address}', RemoveAddressController::class)->name('addresses.remove');
        });
    },
];
