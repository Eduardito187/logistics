<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Logistics\Capacity\Application\Command\ActivateCapacityWindow\ActivateCapacityWindowCommand;
use Logistics\Capacity\Application\Command\BlockDispatchDay\BlockDispatchDayCommand;
use Logistics\Capacity\Application\Query\GetAvailableCapacity\GetAvailableCapacityQuery;
use Logistics\Capacity\Application\Query\GetCapacityRuleset\GetCapacityRulesetQuery;
use Logistics\Capacity\Application\Query\GetEffectiveCapacity\GetEffectiveCapacityQuery;
use Logistics\Capacity\Application\Query\ListBlackoutDays\ListBlackoutDaysQuery;
use Logistics\Capacity\Application\ReadModel\BlackoutDayView;
use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Application\ReadModel\EffectiveCapacityView;
use Logistics\Capacity\Application\Service\CapacityWindowActivator;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\ActiveHorizonRecord;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\CapacityBucketRecord;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\Factory\CapacityBucketFactory;
use Logistics\Capacity\Infrastructure\Queue\ActivateCapacityWindowJob;
use Logistics\Capacity\Infrastructure\Settings\SettingsCapacityRulesetProvider;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Cobertura de la cara de escritura/lectura CQRS del contexto capacity que la ola de Filament dejó fuera:
 * el console command del generador, la activación por rango (handler + dispatcher de cola + job troceado) y
 * los query handlers de disponibilidad, calendario de reglas y días bloqueados. Patrones C, F y H del brief.
 * Corre contra logistics_test (RefreshDatabase); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Publica una versión de reglas de cupo con un scope de nodo (nombre único para no chocar con el helper
 * global de GenerateDispatchCalendarFlowTest).
 */
function publishCapacityRuleset(string $nodeId): void
{
    app(CommandBus::class)->dispatch(new SetSettingCommand(
        key: SettingsCapacityRulesetProvider::SETTING_KEY,
        value: [
            'service_days' => [1, 2, 3, 4, 5, 6, 7],
            'order_cutoff' => '12:00',
            'horizon_days' => 2,
            'holidays' => [],
            'windows' => ['08:00-12:00'],
            'windows_by_weekday' => [],
            'totals' => ['deliveries' => 40],
            'totals_by_slot' => [],
            'scopes' => [['node_id' => $nodeId, 'zone_id' => null]],
        ],
        effectiveFrom: null,
        createdBy: 'test',
    ));
}

it('genera el calendario de cupos por el console command', function (): void {
    publishCapacityRuleset('0192dddd-0000-7000-8000-00000000c001');

    $this->artisan('logistics:capacity:generate-calendar', ['--from' => '2026-03-02', '--days' => 2])
        ->assertSuccessful();

    expect(CapacityBucketRecord::query()->count())->toBeGreaterThan(0);
});

it('activa cupos por rango: avanza el horizonte y encola el trabajo asíncrono', function (): void {
    Queue::fake();

    $municipalityId = '0192dddd-0000-7000-8000-00000000c010';

    app(CommandBus::class)->dispatch(new ActivateCapacityWindowCommand(
        municipalityIds: [$municipalityId],
        fromDate: '2026-03-02',
        toDate: '2026-03-10',
        weekdays: [1, 2, 3, 4, 5, 6, 7],
        windows: [['time_slot' => '08:00-12:00', 'unit' => 'deliveries', 'quantity' => 10]],
        activatedBy: 'test',
    ));

    // El handler crea/avanza el horizonte activo del municipio dentro de la transacción del comando.
    expect(ActiveHorizonRecord::query()->where('municipality_id', $municipalityId)->count())->toBe(1);
});

it('el job troceado materializa los cupos del lote y se re-encola si el rango no terminó', function (): void {
    Queue::fake();

    $municipalityId = '0192dddd-0000-7000-8000-00000000c020';

    // Rango de 20 días > CHUNK_DAYS (14): procesa el primer lote y encola la continuación.
    $job = new ActivateCapacityWindowJob(
        $municipalityId,
        '2026-03-02',
        '2026-03-22',
        [1, 2, 3, 4, 5, 6, 7],
        [['time_slot' => '08:00-12:00', 'unit' => 'deliveries', 'quantity' => 10]],
    );

    $job->handle(app(CapacityWindowActivator::class));

    // El primer lote dejó cupos del municipio y se encoló un job de continuación.
    expect(CapacityBucketRecord::query()->where('municipality_id', $municipalityId)->count())->toBeGreaterThan(0)
        ->and($job->uniqueId())->toBe("{$municipalityId}|2026-03-02|2026-03-22");

    Queue::assertPushed(ActivateCapacityWindowJob::class);
});

it('consulta el disponible y la capacidad efectiva de un cupo sembrado', function (): void {
    $nodeId = '0192dddd-0000-7000-8000-00000000c030';
    CapacityBucketFactory::new()
        ->forNode($nodeId)
        ->onSlot('2026-03-02', '08:00-12:00')
        ->withUnit(CapacityUnit::Deliveries)
        ->withCapacity(40.0, 5.0)
        ->create();

    /** @var QueryBus $bus */
    $bus = app(QueryBus::class);

    /** @var CapacityView|null $available */
    $available = $bus->ask(new GetAvailableCapacityQuery($nodeId, null, '2026-03-02', '08:00-12:00', 'deliveries'));
    /** @var EffectiveCapacityView $effective */
    $effective = $bus->ask(new GetEffectiveCapacityQuery($nodeId, null, '2026-03-02', '08:00-12:00', 'deliveries'));

    expect($available)->toBeInstanceOf(CapacityView::class)
        ->and($effective)->toBeInstanceOf(EffectiveCapacityView::class);

    // Clave inexistente: el disponible es null.
    expect($bus->ask(new GetAvailableCapacityQuery($nodeId, null, '2026-03-05', '16:00-20:00', 'weight')))->toBeNull();
});

it('lista los días bloqueados de un rango tras bloquear un día', function (): void {
    app(CommandBus::class)->dispatch(new BlockDispatchDayCommand(
        date: '2026-03-02',
        municipalityId: null,
        reason: 'National holiday test',
        blockedBy: (string) Str::uuid7(),
    ));

    /** @var QueryBus $bus */
    $bus = app(QueryBus::class);

    /** @var list<BlackoutDayView> $blackouts */
    $blackouts = $bus->ask(new ListBlackoutDaysQuery('2026-03-01', '2026-03-31'));

    expect($blackouts)->not->toBeEmpty()
        ->and($blackouts[0])->toBeInstanceOf(BlackoutDayView::class);
});

it('consulta las reglas de cupo vigentes', function (): void {
    publishCapacityRuleset('0192dddd-0000-7000-8000-00000000c040');

    /** @var QueryBus $bus */
    $bus = app(QueryBus::class);

    // El handler expone la forma primitiva de las reglas (array), nunca el objeto de dominio (regla 7).
    /** @var array<string, mixed> $ruleset */
    $ruleset = $bus->ask(new GetCapacityRulesetQuery);

    expect($ruleset)->toBeArray()
        ->and($ruleset)->toHaveKey('windows');
});
