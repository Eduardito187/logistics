<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Logistics\Planning\Application\Query\ListPlansByDate\ListPlansByDate;
use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Database\Factories\PlanRecordFactory;
use Logistics\Planning\Domain\ValueObject\PlanStatus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * GET /api/v1/app/plans — paginación estándar opt-in, rango de fechas y filtros status/vehicle_id
 * (auditoría app #20). Se omite el middleware de puerta para probar solo el contrato del controller y
 * la cadena CQRS. Fechas fijas lejanas para no depender de "hoy".
 */
beforeEach(function (): void {
    Http::fake(['*' => Http::response(null, 500)]);
    $this->withoutMiddleware();
});

it('sin parámetros de página conserva la respuesta histórica (meta date + count)', function (): void {
    PlanRecordFactory::new()->count(3)->onDate('2031-03-10')->create();

    $this->getJson('/api/v1/app/plans?date=2031-03-10')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.date', '2031-03-10')
        ->assertJsonPath('meta.count', 3)
        ->assertJsonMissingPath('meta.total');
});

it('pagina con page/per_page y devuelve el meta estándar junto con la fecha', function (): void {
    PlanRecordFactory::new()->count(5)->onDate('2031-03-10')->create();

    $this->getJson('/api/v1/app/plans?date=2031-03-10&page=1&per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.date', '2031-03-10')
        ->assertJsonPath('meta.count', 2)
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.total_pages', 3)
        ->assertJsonPath('meta.next_page', 2)
        ->assertJsonPath('meta.prev_page', null);
});

it('respeta los límites de página (última y fuera de rango)', function (): void {
    PlanRecordFactory::new()->count(5)->onDate('2031-03-10')->create();

    $this->getJson('/api/v1/app/plans?date=2031-03-10&page=3&per_page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.next_page', null)
        ->assertJsonPath('meta.prev_page', 2);

    $this->getJson('/api/v1/app/plans?date=2031-03-10&page=4&per_page=2')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 5);
});

it('un día sin planes paginado devuelve total 0 y una sola página', function (): void {
    $this->getJson('/api/v1/app/plans?date=2031-03-11&per_page=10')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.total_pages', 1)
        ->assertJsonPath('meta.next_page', null);
});

it('filtra por estado y por vehículo', function (): void {
    $vehicle = (string) Str::uuid7();
    PlanRecordFactory::new()->onDate('2031-03-10')->published()->create(['vehicle_id' => $vehicle]);
    PlanRecordFactory::new()->onDate('2031-03-10')->proposed()->create();
    PlanRecordFactory::new()->onDate('2031-03-10')->proposed()->create();

    $this->getJson('/api/v1/app/plans?date=2031-03-10&status=PROPOSED')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson("/api/v1/app/plans?date=2031-03-10&vehicle_id={$vehicle}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vehicle_id', $vehicle)
        ->assertJsonPath('data.0.status', 'PUBLISHED');

    $this->getJson('/api/v1/app/plans?date=2031-03-10&status=NOPE')->assertStatus(422);
    $this->getJson('/api/v1/app/plans?date=2031-03-10&vehicle_id=no-uuid')->assertStatus(422);
});

it('lista un rango de fechas en orden de día y valida sus límites', function (): void {
    $vehicle = (string) Str::uuid7();
    PlanRecordFactory::new()->onDate('2031-03-12')->create(['vehicle_id' => $vehicle]);
    PlanRecordFactory::new()->onDate('2031-03-10')->create(['vehicle_id' => $vehicle]);
    PlanRecordFactory::new()->onDate('2031-03-20')->create(['vehicle_id' => $vehicle]);

    $this->getJson('/api/v1/app/plans?date_from=2031-03-10&date_to=2031-03-12')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.plan_date', '2031-03-10')
        ->assertJsonPath('data.1.plan_date', '2031-03-12')
        ->assertJsonPath('meta.date', '2031-03-10')
        ->assertJsonPath('meta.date_to', '2031-03-12')
        ->assertJsonPath('meta.count', 2);

    // Rango + vehículo + paginación combinados.
    $this->getJson("/api/v1/app/plans?date_from=2031-03-01&date_to=2031-03-31&vehicle_id={$vehicle}&per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.next_page', 2);

    // Sin fecha alguna, rango invertido o de más de 31 días: 422.
    $this->getJson('/api/v1/app/plans')->assertStatus(422);
    $this->getJson('/api/v1/app/plans?date_from=2031-03-12&date_to=2031-03-10')->assertStatus(422);
    $this->getJson('/api/v1/app/plans?date_from=2031-03-01&date_to=2031-04-15')->assertStatus(422);
});

it('ListPlansByDate con limit devuelve un Page de PlanView y sin limit la lista histórica', function (): void {
    PlanRecordFactory::new()->count(3)->onDate('2031-03-10')->create();

    $queries = app(QueryBus::class);

    $page = $queries->ask(new ListPlansByDate('2031-03-10', limit: 2, offset: 2));
    expect($page)->toBeInstanceOf(Page::class)
        ->and($page->total)->toBe(3)
        ->and($page->items)->toHaveCount(1)
        ->and($page->items[0])->toBeInstanceOf(PlanView::class);

    expect($queries->ask(new ListPlansByDate('2031-03-10')))->toBeArray()->toHaveCount(3);
});

it('los estados filtrables de la consulta son exactamente los de PlanStatus', function (): void {
    $cases = array_map(static fn (PlanStatus $status): string => $status->value, PlanStatus::cases());

    expect(ListPlansByDate::STATUSES)->toEqualCanonicalizing($cases);
});
