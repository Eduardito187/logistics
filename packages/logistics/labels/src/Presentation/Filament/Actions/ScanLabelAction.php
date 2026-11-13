<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;

/**
 * Acción de encabezado reutilizable que abre el ESCÁNER de etiquetas como MODAL (antes era una página
 * completa). Enciende la cámara desde un switch y lee el QR firmado o el código de barras Zebra con el
 * `BarcodeDetector` NATIVO del navegador (CERO dependencia externa, CERO CDN); también acepta tipear/pegar
 * el código a mano. Cualquiera de los tres caminos resuelve el MISMO bulto por /cockpit/label/resolve.
 *
 * La lógica de resolución NO cambia: solo la presentación (página → modal + switch). Se expone como fábrica
 * para que cualquier página/recurso de oficina (listado de etiquetas, lanzador del menú) monte el mismo modal.
 */
final class ScanLabelAction
{
    public static function make(string $name = 'scanLabel'): Action
    {
        return Action::make($name)
            ->label(__('Scan label'))
            ->icon('heroicon-o-viewfinder-circle')
            ->color('primary')
            ->modalHeading(__('Scan label'))
            // El modal no envía un formulario: el escaneo resuelve y muestra el bulto ahí mismo.
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->modalWidth(Width::Large)
            ->modalContent(fn (): View => view('filament.labels.scan-label-modal'));
    }
}
