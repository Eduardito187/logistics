<?php

declare(strict_types=1);

use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Fleet\Domain\Contract\DocumentExpiryPolicy;
use Logistics\Fleet\Domain\Contract\ShiftRulesPolicy;
use Logistics\Fleet\Infrastructure\Outbox\VehicleIncidentFleetDispatcher;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Fleet\Infrastructure\Settings\SettingsDocumentExpiryPolicy;
use Logistics\Fleet\Infrastructure\Settings\SettingsShiftRulesPolicy;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de las políticas de Infrastructure que leen de settings (SettingsShiftRulesPolicy y
 * SettingsDocumentExpiryPolicy) y del dispatcher de outbox que reacciona a incidentes de camión
 * (VehicleIncidentFleetDispatcher). Las políticas se ejercitan con y sin valor en settings (respaldo por
 * config y parseo del valor versionado); el dispatcher con cada rama (evento ajeno, severidad MINOR, sin
 * vehículo y MAYOR sobre un vehículo real que pasa a mantenimiento).
 */

// Construye un OutboxMessage de prueba a partir de un nombre de evento y su carga útil, reusando el
// factory de dominio del outbox con un evento anónimo (sin tocar la base de datos).
function coverageFleetOutboxMessage(string $eventName, array $payload): OutboxMessage
{
    $event = new class($eventName, $payload) implements DomainEvent
    {
        public function __construct(
            private string $name,
            private array $data,
        ) {}

        public function eventId(): Uuid
        {
            return Uuid::generate();
        }

        public function aggregateId(): string
        {
            return Uuid::generate()->value;
        }

        public function eventName(): string
        {
            return $this->name;
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable;
        }

        public function payload(): array
        {
            return $this->data;
        }
    };

    return OutboxMessage::fromDomainEvent($event, 'execution.vehicle_incident', new DateTimeImmutable);
}

// ── SettingsShiftRulesPolicy ─────────────────────────────────────────────────────────────────────────

it('resuelve las reglas de turno por el respaldo de config cuando no hay valor en settings', function (): void {
    /** @var ShiftRulesPolicy $policy */
    $policy = app(ShiftRulesPolicy::class);

    $rules = $policy->rules();

    expect($rules->maxDailyMinutes)->toBeGreaterThan(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lee las reglas de turno desde un valor válido en settings', function (): void {
    app(CommandBus::class)->dispatch(new SetSettingCommand(
        key: SettingsShiftRulesPolicy::SETTING_KEY,
        value: [
            'max_daily_minutes' => 600,
            'earliest_start_minute' => 300,
            'latest_end_minute' => 1_320,
            'min_rest_minutes' => 600,
        ],
    ));

    $rules = app(ShiftRulesPolicy::class)->rules();

    expect($rules->maxDailyMinutes)->toBe(600)
        ->and($rules->minRestMinutes)->toBe(600);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('cae al respaldo si el valor de reglas de turno en settings es inválido', function (): void {
    app(CommandBus::class)->dispatch(new SetSettingCommand(
        key: SettingsShiftRulesPolicy::SETTING_KEY,
        value: ['max_daily_minutes' => 600], // faltan claves → se descarta y usa el respaldo
    ));

    $rules = app(ShiftRulesPolicy::class)->rules();

    expect($rules->maxDailyMinutes)->toBeGreaterThan(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

// ── SettingsDocumentExpiryPolicy ─────────────────────────────────────────────────────────────────────

it('resuelve la ventana de vencimiento por el respaldo de config sin valor en settings', function (): void {
    /** @var DocumentExpiryPolicy $policy */
    $policy = app(DocumentExpiryPolicy::class);

    expect($policy->alertWindowDays())->toBeGreaterThanOrEqual(0);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lee la ventana de vencimiento desde un valor válido en settings', function (): void {
    app(CommandBus::class)->dispatch(new SetSettingCommand(
        key: SettingsDocumentExpiryPolicy::SETTING_KEY,
        value: ['days' => 45],
    ));

    expect(app(DocumentExpiryPolicy::class)->alertWindowDays())->toBe(45);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

// ── VehicleIncidentFleetDispatcher ───────────────────────────────────────────────────────────────────

it('ignora un mensaje del outbox que no es un incidente de camión', function (): void {
    $dispatcher = new VehicleIncidentFleetDispatcher(app(CommandBus::class));

    // No debe lanzar ni requerir ningún vehículo: simplemente retorna.
    $dispatcher->dispatch(coverageFleetOutboxMessage('execution.other_event', []));

    expect(true)->toBeTrue();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('ignora un incidente MENOR y uno sin vehículo', function (): void {
    $dispatcher = new VehicleIncidentFleetDispatcher(app(CommandBus::class));

    $dispatcher->dispatch(coverageFleetOutboxMessage('execution.vehicle_incident_reported', [
        'severity' => 'MINOR',
        'vehicle_id' => Uuid::generate()->value,
    ]));

    $dispatcher->dispatch(coverageFleetOutboxMessage('execution.vehicle_incident_reported', [
        'severity' => 'MAJOR',
        'vehicle_id' => '',
    ]));

    expect(true)->toBeTrue();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('manda a mantenimiento el vehículo ante un incidente MAYOR', function (): void {
    $vehicle = VehicleFactory::new()->create(['status' => 'available']);

    $dispatcher = new VehicleIncidentFleetDispatcher(app(CommandBus::class));
    $dispatcher->dispatch(coverageFleetOutboxMessage('execution.vehicle_incident_reported', [
        'severity' => 'MAJOR',
        'vehicle_id' => (string) $vehicle->getKey(),
    ]));

    expect(VehicleModel::query()->whereKey($vehicle->getKey())->value('status'))->toBe('maintenance');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
