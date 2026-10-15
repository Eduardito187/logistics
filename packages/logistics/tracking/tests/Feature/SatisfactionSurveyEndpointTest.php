<?php

declare(strict_types=1);

use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\SatisfactionSurveyRecord;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Contrato HTTP del endpoint público de la encuesta de satisfacción (K-06).
 *
 * Se omite el middleware (rate-limit) para ejercitar solo el contrato del payload y las reglas. Corre
 * contra logistics_test (RefreshDatabase).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

$delivered = function (): array {
    $shipment = ShipmentRecord::factory()->create();
    $token = TrackingToken::generate()->toString();
    TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'token' => $token,
        'status' => 'DELIVERED',
        'eta' => null,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);

    return ['shipment' => $shipment, 'token' => $token];
};

it('registra la calificación de un envío entregado', function () use ($delivered): void {
    ['shipment' => $shipment, 'token' => $token] = $delivered();

    $this->postJson("/t/{$token}/survey", [
        'rating' => 5,
        'comment' => 'Llegó rapidísimo, excelente.',
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.recorded', true);

    $survey = SatisfactionSurveyRecord::query()->where('shipment_id', $shipment->id)->first();
    expect($survey)->not->toBeNull();
    expect($survey->rating)->toBe(5);
    expect($survey->comment)->toBe('Llegó rapidísimo, excelente.');
});

it('rechaza calificar un envío que aún no fue entregado', function (): void {
    $shipment = ShipmentRecord::factory()->create();
    $token = TrackingToken::generate()->toString();
    TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'token' => $token,
        'status' => 'ON_THE_WAY',
        'eta' => null,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);

    $this->postJson("/t/{$token}/survey", ['rating' => 4])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'survey_not_available');

    expect(SatisfactionSurveyRecord::query()->where('shipment_id', $shipment->id)->exists())->toBeFalse();
});

it('rechaza una segunda respuesta para el mismo envío (una por envío)', function () use ($delivered): void {
    ['token' => $token] = $delivered();

    $this->postJson("/t/{$token}/survey", ['rating' => 5])->assertStatus(201);

    $this->postJson("/t/{$token}/survey", ['rating' => 1])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'survey_already_recorded');
});

it('rechaza con 404 genérico un token inexistente', function (): void {
    $this->postJson('/t/'.TrackingToken::generate()->toString().'/survey', ['rating' => 5])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'tracking_not_found');
});

it('valida que la calificación esté entre 1 y 5', function () use ($delivered): void {
    ['token' => $token] = $delivered();

    $this->postJson("/t/{$token}/survey", ['rating' => 9])
        ->assertStatus(422);
});
