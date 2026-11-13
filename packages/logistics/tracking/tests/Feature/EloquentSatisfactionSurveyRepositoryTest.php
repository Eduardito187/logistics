<?php

declare(strict_types=1);

use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Domain\Contract\SatisfactionSurveyRepository;
use Logistics\Tracking\Domain\Model\SatisfactionSurvey;
use Logistics\Tracking\Domain\ValueObject\Rating;
use Logistics\Tracking\Domain\ValueObject\SurveyId;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\SatisfactionSurveyRecord;

/**
 * Repositorio Eloquent de encuestas de satisfacción: upsert por id y verificación de unicidad por envío
 * (una respuesta por envío). Contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function essRepo(): SatisfactionSurveyRepository
{
    return app(SatisfactionSurveyRepository::class);
}

it('genera una identidad nueva', function (): void {
    expect(essRepo()->nextIdentity())->toBeInstanceOf(SurveyId::class);
});

it('persiste una encuesta y la reconoce por envío', function (): void {
    $shipment = Uuid::generate()->value;
    $survey = SatisfactionSurvey::record(
        SurveyId::generate(),
        $shipment,
        'token-ess-1',
        Rating::of(5),
        'Todo bien',
        null,
        new DateTimeImmutable('2026-11-10T10:00:00+00:00'),
    );

    essRepo()->save($survey);

    expect(SatisfactionSurveyRecord::query()->where('shipment_id', $shipment)->exists())->toBeTrue()
        ->and(essRepo()->existsForShipment($shipment))->toBeTrue();
});

it('no reconoce un envío sin encuesta', function (): void {
    expect(essRepo()->existsForShipment(Uuid::generate()->value))->toBeFalse();
});
