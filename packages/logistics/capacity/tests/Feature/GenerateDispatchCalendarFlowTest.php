<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Capacity\Application\Command\GenerateDispatchCalendar\GenerateDispatchCalendar;
use Logistics\Capacity\Application\Command\GenerateDispatchCalendar\GenerateDispatchCalendarHandler;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\CapacityBucketRecord;
use Logistics\Capacity\Infrastructure\Settings\SettingsCapacityRulesetProvider;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Flujo real del generador de calendario contra PostgreSQL (logistics_test): publica una versión de
 * reglas en settings, corre el generador y comprueba que materializa los cupos del horizonte vía el
 * CommandBus (con sus eventos en el outbox) y que re-ejecutarlo es idempotente.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Publica una versión de reglas de cupo con un scope de nodo y un horizonte corto.
 */
function publishRuleset(string $nodeId): void
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

it('materializes the horizon from the published rules and records outbox events', function (): void {
    $nodeId = '019299aa-0000-7000-8000-0000000000aa';
    publishRuleset($nodeId);

    /** @var GenerateDispatchCalendarHandler $handler */
    $handler = app(GenerateDispatchCalendarHandler::class);
    $handler->handle(new GenerateDispatchCalendar(from: '2026-03-02'));

    // 2 días de servicio × 1 ventana × 1 unidad × 1 scope = 2 cupos.
    expect($handler->lastCreated())->toBe(2)
        ->and($handler->lastSkipped())->toBe(0);

    $buckets = CapacityBucketRecord::query()->where('node_id', $nodeId)->get();
    expect($buckets)->toHaveCount(2)
        ->and($buckets->pluck('slot_date')->map(static fn ($d): string => substr((string) $d, 0, 10))->sort()->values()->all())
        ->toBe(['2026-03-02', '2026-03-03']);

    // Cada DefineCapacity publica su evento al outbox dentro de la misma transacción.
    $events = DB::table('outbox_messages')->where('aggregate_type', 'capacity.bucket')->count();
    expect($events)->toBe(2);
});

it('is idempotent: a second run creates no new buckets', function (): void {
    $nodeId = '019299aa-0000-7000-8000-0000000000bb';
    publishRuleset($nodeId);

    $handler = app(GenerateDispatchCalendarHandler::class);
    $handler->handle(new GenerateDispatchCalendar(from: '2026-03-02'));
    expect($handler->lastCreated())->toBe(2);

    // Segunda corrida: todo ya existe, no se crea nada.
    $handler->handle(new GenerateDispatchCalendar(from: '2026-03-02'));
    expect($handler->lastCreated())->toBe(0)
        ->and($handler->lastSkipped())->toBe(2)
        ->and(CapacityBucketRecord::query()->where('node_id', $nodeId)->count())->toBe(2);
});
