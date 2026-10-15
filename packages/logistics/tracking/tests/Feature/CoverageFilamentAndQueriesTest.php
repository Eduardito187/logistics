<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Application\Query\GetSatisfactionSummary\GetSatisfactionSummary;
use Logistics\Tracking\Application\ReadModel\SatisfactionSummaryView;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\SatisfactionSurveyRecord;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;
use Logistics\Tracking\Presentation\Filament\Resources\SatisfactionSurveyResource\Pages\ListSatisfactionSurveys;
use Logistics\Tracking\Presentation\Filament\Resources\SatisfactionSurveyResource\Pages\ViewSatisfactionSurvey;
use Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource\Pages\ListShipmentTrackings;
use Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource\Pages\ViewShipmentTracking;
use Logistics\Tracking\Presentation\Filament\Widgets\SatisfactionStatsWidget;

/**
 * Cobertura de la capa de presentación Filament del contexto tracking: recursos de rastreo de envíos y
 * de encuestas de satisfacción, más el widget de estadísticas (K-06). Montar cada componente ejecuta
 * las definiciones de table()/infolist() y, en el widget, el resumen por el QueryBus. Se incluye el
 * happy-path del QueryHandler GetSatisfactionSummary (Pattern C) con respuestas reales sembradas.
 *
 * Corre contra logistics_test (RefreshDatabase); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra una respuesta de encuesta para un envío entregado (una por envío).
 */
function seedSurvey(int $rating, ?string $comment = null): SatisfactionSurveyRecord
{
    $shipment = ShipmentRecord::factory()->create();

    return SatisfactionSurveyRecord::query()->create([
        'id' => (string) Str::uuid7(),
        'shipment_id' => $shipment->id,
        'token' => TrackingToken::generate()->toString(),
        'rating' => $rating,
        'comment' => $comment,
        'reason' => null,
    ]);
}

it('renderiza el listado de rastreo de envíos', function (): void {
    Livewire::test(ListShipmentTrackings::class)->assertOk();
});

it('renderiza el detalle de rastreo de un envío', function (): void {
    $shipment = ShipmentRecord::factory()->create();
    $tracking = TrackingRecord::factory()->create([
        'shipment_id' => $shipment->id,
        'eta' => null,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);

    Livewire::test(ViewShipmentTracking::class, ['record' => $tracking->getKey()])->assertOk();
});

it('renderiza el listado de encuestas de satisfacción', function (): void {
    Livewire::test(ListSatisfactionSurveys::class)->assertOk();
});

it('renderiza el detalle de una encuesta de satisfacción', function (): void {
    $survey = seedSurvey(5, 'Excelente servicio.');

    Livewire::test(ViewSatisfactionSurvey::class, ['record' => $survey->id])->assertOk();
});

it('renderiza el widget de satisfacción sin datos', function (): void {
    Livewire::test(SatisfactionStatsWidget::class)->assertOk();
});

it('renderiza el widget de satisfacción con respuestas', function (): void {
    seedSurvey(5, 'Rapidísimo.');
    seedSurvey(4);
    seedSurvey(2, 'Llegó tarde.');

    Livewire::test(SatisfactionStatsWidget::class)->assertOk();
});

it('resume la satisfacción por el QueryBus con respuestas reales', function (): void {
    seedSurvey(5, 'Rapidísimo.');
    seedSurvey(5, 'Perfecto.');
    seedSurvey(2, 'Llegó tarde.');

    $summary = app(QueryBus::class)->ask(new GetSatisfactionSummary(10));

    expect($summary)->toBeInstanceOf(SatisfactionSummaryView::class);
    /** @var SatisfactionSummaryView $summary */
    expect($summary->responseCount)->toBe(3)
        ->and($summary->promoters)->toBe(2)
        ->and($summary->detractors)->toBe(1)
        ->and($summary->averageRating)->not->toBeNull()
        ->and($summary->nps)->not->toBeNull();
});
