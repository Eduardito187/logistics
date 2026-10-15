<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Tracking\Domain\Model\SatisfactionSurvey;
use Logistics\Tracking\Domain\ValueObject\Rating;
use Logistics\Tracking\Domain\ValueObject\SurveyId;
use Logistics\Tracking\Domain\ValueObject\SurveyReason;

/**
 * Traduce entre el agregado SatisfactionSurvey y su fila Eloquent. Toda la conversión a/desde
 * primitivos vive acá, para que ni el dominio ni el repositorio conozcan la forma de la tabla.
 */
final class SatisfactionSurveyMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(SatisfactionSurvey $survey): array
    {
        return [
            'id' => $survey->id()->toString(),
            'shipment_id' => $survey->shipmentId(),
            'token' => $survey->token(),
            'rating' => $survey->rating()->value,
            'comment' => $survey->comment(),
            'reason' => $survey->reason()?->value,
        ];
    }

    public function toDomain(SatisfactionSurveyRecord $record): SatisfactionSurvey
    {
        $submittedAt = $record->created_at?->toDateTimeImmutable() ?? new DateTimeImmutable;

        return SatisfactionSurvey::reconstitute(
            SurveyId::fromString($record->id),
            $record->shipment_id,
            $record->token,
            Rating::of($record->rating),
            $record->comment,
            $record->reason !== null ? SurveyReason::tryFrom($record->reason) : null,
            $submittedAt,
        );
    }
}
