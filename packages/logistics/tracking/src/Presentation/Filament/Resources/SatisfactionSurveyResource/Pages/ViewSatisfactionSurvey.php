<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Filament\Resources\SatisfactionSurveyResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Tracking\Presentation\Filament\Resources\SatisfactionSurveyResource;

/**
 * Detalle de una respuesta de encuesta: calificación, motivo, comentario y envío calificado. Solo
 * lectura (no hay edición del dato que envió el cliente).
 */
final class ViewSatisfactionSurvey extends ViewRecord
{
    protected static string $resource = SatisfactionSurveyResource::class;
}
