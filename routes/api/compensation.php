<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Compensation\Presentation\Http\Controller\GetCompensationCaseController;
use Logistics\Compensation\Presentation\Http\Controller\HoldVoucherController;
use Logistics\Compensation\Presentation\Http\Controller\OpenCompensationCaseController;
use Logistics\Compensation\Presentation\Http\Controller\RedeemVoucherController;
use Logistics\Compensation\Presentation\Http\Controller\ReleaseVoucherHoldController;
use Logistics\Compensation\Presentation\Http\Controller\ValidateVoucherController;

/*
| Fragmento de rutas del contexto Compensación (vales/cupones por problemas de entrega).
|
| Puerta `integrations` (credenciales de servicio: POS / call center). El cliente no canjea directo: el
| POS/checkout valida y canjea el vale. Rutas relativas a la puerta.
*/

return [
    'integrations' => static function (): void {
        // Vales de un solo uso: validar (checkout/POS), reservar para una venta en curso, canjear
        // (idempotente por sale_ref) y liberar la reserva.
        Route::prefix('vouchers')->name('vouchers.')->group(function (): void {
            Route::post('validate', ValidateVoucherController::class)->name('validate');
            Route::post('{code}/hold', HoldVoucherController::class)->name('hold');
            Route::post('{code}/redeem', RedeemVoucherController::class)->name('redeem');
            Route::post('{code}/release', ReleaseVoucherHoldController::class)->name('release');
        });

        // Casos de compensación: abrir uno manual (call center/supervisor) y consultarlo.
        Route::prefix('compensation')->name('compensation.')->group(function (): void {
            Route::post('cases', OpenCompensationCaseController::class)->name('cases.open');
            Route::get('cases/{caseId}', GetCompensationCaseController::class)->name('cases.show');
        });
    },
];
