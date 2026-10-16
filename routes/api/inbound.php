<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Logistics\Inbound\Presentation\Http\Controller\AnnounceAsnController;
use Logistics\Inbound\Presentation\Http\Controller\GetAsnController;
use Logistics\Inbound\Presentation\Http\Controller\GetReceivingCalendarController;
use Logistics\Inbound\Presentation\Http\Controller\LabelReceivedUnitsController;
use Logistics\Inbound\Presentation\Http\Controller\ListAsnsController;
use Logistics\Inbound\Presentation\Http\Controller\RecordArrivalController;
use Logistics\Inbound\Presentation\Http\Controller\RecordGoodsReceiptController;
use Logistics\Inbound\Presentation\Http\Controller\ScheduleAsnController;

/*
 * Rutas del contexto Inbound (marketplace, Fase 2). Todo en la puerta `integrations` (credenciales de
 * servicio): el seller anuncia y gestiona sus ASNs por API, no la app de campo ni los canales.
 * Contrato en openapi/v1.yaml (ADR 017).
 */
return [
    'integrations' => static function (): void {
        Route::prefix('inbound')->name('inbound.')->group(function (): void {
            Route::post('asn', AnnounceAsnController::class)->name('asn.announce');
            Route::get('asn', ListAsnsController::class)->name('asn.index');
            Route::get('asn/{asnId}', GetAsnController::class)->name('asn.show');
            Route::put('asn/{asnId}/schedule', ScheduleAsnController::class)->name('asn.schedule');
            Route::post('asn/arrival', RecordArrivalController::class)->name('asn.arrival');
            Route::post('asn/receipt', RecordGoodsReceiptController::class)->name('asn.receipt');
            Route::post('asn/label', LabelReceivedUnitsController::class)->name('asn.label');
            Route::get('receiving-calendar', GetReceivingCalendarController::class)->name('receiving-calendar');
        });
    },
];
