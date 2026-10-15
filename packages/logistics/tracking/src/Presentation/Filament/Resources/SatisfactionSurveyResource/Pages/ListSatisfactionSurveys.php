<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Filament\Resources\SatisfactionSurveyResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;
use Logistics\Tracking\Presentation\Filament\Resources\SatisfactionSurveyResource;
use Logistics\Tracking\Presentation\Filament\Widgets\SatisfactionStatsWidget;

/**
 * Listado de respuestas de la encuesta de satisfacción (solo lectura). La cabecera muestra el resumen
 * de NPS/promedio/volumen.
 */
final class ListSatisfactionSurveys extends ListRecords
{
    protected static string $resource = SatisfactionSurveyResource::class;

    /**
     * Widgets de cabecera: el resumen de satisfacción.
     *
     * @return array<int, class-string<Widget>>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            SatisfactionStatsWidget::class,
        ];
    }
}
