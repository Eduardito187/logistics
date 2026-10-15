<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use Logistics\Tracking\Domain\Contract\SatisfactionSurveyRepository;
use Logistics\Tracking\Domain\Model\SatisfactionSurvey;
use Logistics\Tracking\Domain\ValueObject\SurveyId;

/**
 * Implementación Eloquent del repositorio de encuestas de satisfacción.
 *
 * save() es un upsert por id; la regla "una por envío" la respalda el índice único de shipment_id.
 */
final class EloquentSatisfactionSurveyRepository implements SatisfactionSurveyRepository
{
    public function __construct(
        private readonly SatisfactionSurveyMapper $mapper,
    ) {}

    public function nextIdentity(): SurveyId
    {
        return SurveyId::generate();
    }

    public function save(SatisfactionSurvey $survey): void
    {
        SatisfactionSurveyRecord::query()->updateOrCreate(
            ['id' => $survey->id()->toString()],
            $this->mapper->toRow($survey),
        );
    }

    public function existsForShipment(string $shipmentId): bool
    {
        return SatisfactionSurveyRecord::query()
            ->where('shipment_id', $shipmentId)
            ->exists();
    }
}
