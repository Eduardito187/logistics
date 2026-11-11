<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;

/**
 * Escáner de etiquetas en la WEB: usa la cámara del dispositivo y el `BarcodeDetector` nativo del navegador
 * (CERO dependencia externa, CERO CDN) para leer el QR firmado O el código de barras Zebra (Code128) de la
 * etiqueta. Cualquiera de los dos resuelve el MISMO bulto (vía /cockpit/label/resolve → ResolveLabelUnit).
 * También acepta tipear/pegar el código único a mano (respaldo si no hay cámara o BarcodeDetector).
 *
 * Solo oficina (admin/supervisor). Texto visible en inglés (regla 5); comentarios en español (regla 6).
 */
final class ScanLabelPage extends Page
{
    use OfficeOnly;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-viewfinder-circle';

    protected static ?int $navigationSort = 42;

    protected string $view = 'filament.labels.scan-label';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('Scan label');
    }

    public function getTitle(): string
    {
        return __('Scan label');
    }
}
