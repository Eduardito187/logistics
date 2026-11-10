<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Cockpit\ReportBuilder;
use BackedEnum;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes de operaciones (Fase 5 — reportes y exportación): descarga de la analítica a CSV (series diarias,
 * distribución por estado, efectividad, concentración por nodo) y vista previa del resumen diario que se
 * envía por correo como digest. Página cockpit de oficina; reusa {@see ReportBuilder} (una sola fuente de
 * verdad con la Analítica). SOLO lectura; sin UUID visible.
 */
final class Reports extends Page
{
    use OfficeOnly;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.reports';

    public static function getNavigationGroup(): string
    {
        return __('Operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'Reportes';
    }

    public function getTitle(): string
    {
        return 'Reportes';
    }

    public function getHeading(): string
    {
        return '';
    }

    /**
     * Datasets exportables (clave → etiqueta legible) para los botones de descarga.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function datasets(): array
    {
        return app(ReportBuilder::class)->datasets();
    }

    /**
     * Resumen diario (vista previa de lo que va en el digest por correo).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function summary(): array
    {
        return app(ReportBuilder::class)->dailySummary();
    }

    /**
     * Descarga un dataset como CSV real (streamed). Cabeceras en español; nunca UUID. Dataset desconocido
     * devuelve un CSV vacío con cabecera.
     */
    public function exportCsv(string $dataset): StreamedResponse
    {
        $csv = app(ReportBuilder::class)->csv($dataset);

        return response()->streamDownload(function () use ($csv): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            // BOM UTF-8 para que Excel abra bien los acentos.
            fwrite($out, "\xEF\xBB\xBF");
            if ($csv['headers'] !== []) {
                fputcsv($out, $csv['headers']);
            }
            foreach ($csv['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $csv['filename'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
