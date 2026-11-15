<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\ActiveHorizonRecord;
use Logistics\Capacity\Infrastructure\Queue\ActivateCapacityWindowJob;
use Logistics\Coverage\Application\Contract\MunicipalityCatalog;
use Logistics\Coverage\Application\ReadModel\MunicipalityView;

/**
 * Catálogos y apertura simple del calendario de despacho para la app (puerta app):
 *  - GET  /api/v1/app/delivery-calendar/options       municipios + franjas + unidades + ventanas por defecto
 *  - POST /api/v1/app/delivery-calendar/open-default  habilita una fecha o rango con los cupos por defecto
 *
 * Se omite el middleware de puerta para ejercitar el contrato del controller y la cadena CQRS detrás. El
 * catálogo de municipios de coverage se reemplaza por uno en memoria (su contrato de Application), así el
 * test no depende de sembrar la jerarquía geográfica. Las reglas de cupo salen de la config del package
 * (no hay versión publicada en settings). Corre contra logistics_test; se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();

    config([
        'logistics-capacity.dispatch_calendar.business_days' => [1, 2, 3, 4, 5, 6],
        'logistics-capacity.dispatch_calendar.windows' => ['08:00-12:00', '16:00-20:00'],
        'logistics-capacity.dispatch_calendar.default_slot_deliveries' => 40,
        'logistics-capacity.default_totals.weight' => 8000,
        'logistics-capacity.default_totals.volume' => 0,
    ]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Reemplaza el catálogo de municipios de coverage por uno en memoria con las vistas dadas.
 *
 * @param list<MunicipalityView> $views
 */
function fakeDeliveryCalendarMunicipalities(array $views): void
{
    app()->bind(MunicipalityCatalog::class, static fn (): MunicipalityCatalog => new class($views) implements MunicipalityCatalog
    {
        /**
         * @param list<MunicipalityView> $views
         */
        public function __construct(private array $views) {}

        public function all(): array
        {
            return $this->views;
        }
    });
}

/**
 * @return list<MunicipalityView>
 */
function deliveryCalendarMunicipalityViews(): array
{
    return [
        new MunicipalityView('0192eeee-0000-7000-8000-0000000000a1', 'Cochabamba', '0192eeee-0000-7000-8000-0000000000d1', 'Cochabamba'),
        new MunicipalityView('0192eeee-0000-7000-8000-0000000000a2', 'Santa Cruz de la Sierra', '0192eeee-0000-7000-8000-0000000000d2', 'Santa Cruz'),
    ];
}

it('devuelve en un solo request los catálogos para activar cupos', function (): void {
    fakeDeliveryCalendarMunicipalities(deliveryCalendarMunicipalityViews());

    $response = $this->getJson('/api/v1/app/delivery-calendar/options')->assertOk();

    $response
        ->assertJsonPath('data.municipalities.0.id', '0192eeee-0000-7000-8000-0000000000a1')
        ->assertJsonPath('data.municipalities.0.name', 'Cochabamba')
        ->assertJsonPath('data.municipalities.1.department_name', 'Santa Cruz')
        ->assertJsonPath('data.municipalities.1.label', 'Santa Cruz de la Sierra — Santa Cruz')
        ->assertJsonPath('data.time_slots', ['08:00-12:00', '16:00-20:00'])
        ->assertJsonPath('data.time_slot_format', 'HH:MM-HH:MM')
        ->assertJsonPath('data.weekdays', [1, 2, 3, 4, 5, 6, 7])
        ->assertJsonPath('data.service_days', [1, 2, 3, 4, 5, 6]);

    // Unidades: exactamente los valores que acepta una ventana de activación.
    expect(array_column($response->json('data.units'), 'value'))
        ->toBe(['deliveries', 'weight', 'volume', 'appointments']);

    // Ventanas por defecto: franja × unidad con cupo; el volumen en cero no abre capacidad y se omite.
    $windows = $response->json('data.default_windows');
    expect($windows)->toHaveCount(4)
        ->and($windows[0]['time_slot'])->toBe('08:00-12:00')
        ->and($windows[0]['unit'])->toBe('deliveries')
        ->and((float) $windows[0]['quantity'])->toBe(40.0)
        ->and($windows[1]['unit'])->toBe('weight')
        ->and((float) $windows[1]['quantity'])->toBe(8000.0)
        ->and(array_unique(array_column($windows, 'unit')))->not->toContain('volume');
});

it('habilita una fecha con los cupos por defecto para los municipios del catálogo completo', function (): void {
    Queue::fake();
    fakeDeliveryCalendarMunicipalities(deliveryCalendarMunicipalityViews());

    $response = $this->postJson('/api/v1/app/delivery-calendar/open-default', ['date' => '2027-03-01'])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.from', '2027-03-01')
        ->assertJsonPath('data.to', '2027-03-01')
        ->assertJsonPath('data.weekdays', [1, 2, 3, 4, 5, 6, 7])
        ->assertJsonPath('data.all_municipalities', true)
        ->assertJsonPath('data.municipalities_count', 2)
        ->assertJsonPath('data.municipality_ids', [
            '0192eeee-0000-7000-8000-0000000000a1',
            '0192eeee-0000-7000-8000-0000000000a2',
        ]);

    expect($response->json('data.windows'))->toHaveCount(4);

    // El comando avanzó el horizonte de cada municipio y encoló un trabajo por municipio.
    expect(ActiveHorizonRecord::query()->whereIn('municipality_id', [
        '0192eeee-0000-7000-8000-0000000000a1',
        '0192eeee-0000-7000-8000-0000000000a2',
    ])->count())->toBe(2);
    Queue::assertPushed(ActivateCapacityWindowJob::class, 2);
});

it('habilita un rango para los municipios y días de la semana pedidos', function (): void {
    Queue::fake();
    // Catálogo vacío a propósito: con municipality_ids explícitos no se consulta "los del catálogo".
    fakeDeliveryCalendarMunicipalities([]);

    $this->postJson('/api/v1/app/delivery-calendar/open-default', [
        'from' => '2027-03-01',
        'to' => '2027-03-14',
        'municipality_ids' => ['0192eeee-0000-7000-8000-0000000000b1'],
        'weekdays' => [6, 1, 1],
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.from', '2027-03-01')
        ->assertJsonPath('data.to', '2027-03-14')
        ->assertJsonPath('data.weekdays', [1, 6])
        ->assertJsonPath('data.all_municipalities', false)
        ->assertJsonPath('data.municipalities_count', 1)
        ->assertJsonPath('data.municipality_ids', ['0192eeee-0000-7000-8000-0000000000b1']);

    expect(ActiveHorizonRecord::query()->where('municipality_id', '0192eeee-0000-7000-8000-0000000000b1')->count())->toBe(1);
    Queue::assertPushed(ActivateCapacityWindowJob::class, 1);
});

it('responde 422 con el motivo cuando las reglas no definen ventanas por defecto', function (): void {
    Queue::fake();
    fakeDeliveryCalendarMunicipalities(deliveryCalendarMunicipalityViews());
    config(['logistics-capacity.dispatch_calendar.windows' => []]);

    $response = $this->postJson('/api/v1/app/delivery-calendar/open-default', ['date' => '2027-03-01'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation')
        ->assertJsonPath('status', 422);

    expect($response->json('detail'))->toContain('ventanas por defecto')
        ->and($response->json('error.message'))->toBe($response->json('detail'));
    Queue::assertNothingPushed();
});

it('responde 422 con el motivo cuando no hay municipios en el catálogo', function (): void {
    Queue::fake();
    fakeDeliveryCalendarMunicipalities([]);

    $response = $this->postJson('/api/v1/app/delivery-calendar/open-default', ['date' => '2027-03-01'])
        ->assertStatus(422);

    expect($response->json('detail'))->toContain('no hay municipios')
        ->and($response->json('error.message'))->toBe($response->json('detail'));
    Queue::assertNothingPushed();
});

it('rechaza con 422 el pedido sin fecha, con fecha y rango a la vez, o con rango invertido', function (): void {
    fakeDeliveryCalendarMunicipalities(deliveryCalendarMunicipalityViews());

    $this->postJson('/api/v1/app/delivery-calendar/open-default', [])->assertStatus(422);
    $this->postJson('/api/v1/app/delivery-calendar/open-default', ['from' => '2027-03-01'])->assertStatus(422);
    $this->postJson('/api/v1/app/delivery-calendar/open-default', [
        'date' => '2027-03-01', 'from' => '2027-03-01', 'to' => '2027-03-02',
    ])->assertStatus(422);
    $this->postJson('/api/v1/app/delivery-calendar/open-default', [
        'from' => '2027-03-10', 'to' => '2027-03-01',
    ])->assertStatus(422);
    $this->postJson('/api/v1/app/delivery-calendar/open-default', [
        'date' => '2027-03-01', 'weekdays' => [8],
    ])->assertStatus(422);
});
