<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationStatus;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\SapNotificationRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\SapNotificationResource\Pages\ListSapNotifications;

/**
 * Cobertura del recurso Filament de avisos a SAP (bitácora de solo lectura). Montar la página ejecuta la
 * definición de table()/columns/filters y las acciones; la acción «Retry» recorre el CommandBus
 * (RetrySapNotification) sin tocar SAP. Todo es lectura/escritura contra la base real (logistics_test);
 * no hay factory del Record, así que las filas se crean por asignación masiva (el Record tiene $guarded = []).
 */
beforeEach(function (): void {
    // El reintento encola el envío en la cola `sap`; se fakea para que el job no corra en el test y el
    // aviso quede en el estado directo del comando (Pending), sin que un envío síncrono lo mueva.
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

/**
 * Crea una fila de aviso a SAP en el estado dado (sin factory: el Record admite asignación masiva).
 */
function sapNotificationRow(string $status, string $eventName = 'execution.shipment_delivered', ?string $lastError = null): SapNotificationRecord
{
    return SapNotificationRecord::query()->create([
        'id' => SapNotificationId::generate()->toString(),
        'event_id' => SapNotificationId::generate()->toString(),
        'event_name' => $eventName,
        'aggregate_id' => SapNotificationId::generate()->toString(),
        'payload' => ['a' => 1],
        'occurred_at' => now(),
        'status' => $status,
        'attempts' => $status === SapNotificationStatus::Failed->value ? 3 : 0,
        'last_error' => $lastError,
        'response_status' => $status === SapNotificationStatus::Sent->value ? 202 : null,
        'response_body' => $status === SapNotificationStatus::Sent->value ? '{"ok":true}' : null,
        'sent_at' => $status === SapNotificationStatus::Sent->value ? now() : null,
        'next_attempt_at' => null,
    ]);
}

it('renderiza el listado de avisos a SAP con datos en todos los estados', function (): void {
    sapNotificationRow(SapNotificationStatus::Pending->value);
    sapNotificationRow(SapNotificationStatus::Sent->value);
    sapNotificationRow(SapNotificationStatus::Failed->value, lastError: 'SAP respondió 503 al enviar el aviso');
    sapNotificationRow(SapNotificationStatus::Skipped->value);
    // Evento fuera del catálogo: ejercita la rama de humanización de la etiqueta.
    sapNotificationRow(SapNotificationStatus::Pending->value, eventName: 'telemetry.vehicle_position_reported');

    Livewire::test(ListSapNotifications::class)->assertOk();
});

it('filtra el listado por estado y por evento', function (): void {
    sapNotificationRow(SapNotificationStatus::Failed->value, lastError: 'cayó la red');
    sapNotificationRow(SapNotificationStatus::Sent->value);

    Livewire::test(ListSapNotifications::class)
        ->set('tableFilters.status.value', SapNotificationStatus::Failed->value)
        ->assertOk()
        ->set('tableFilters.event_name.value', 'execution.shipment_delivered')
        ->assertOk();
});

it('reintenta un aviso fallido desde la acción de fila: lo reinicia y lo deja Pending', function (): void {
    $record = sapNotificationRow(SapNotificationStatus::Failed->value, lastError: 'SAP 503');

    Livewire::test(ListSapNotifications::class)
        ->callTableAction('retry', $record->getKey())
        ->assertOk();

    $record->refresh();
    expect($record->status)->toBe(SapNotificationStatus::Pending->value)
        ->and($record->attempts)->toBe(0)
        ->and($record->last_error)->toBeNull();
});

it('reintenta un aviso skipped desde la acción de fila', function (): void {
    $record = sapNotificationRow(SapNotificationStatus::Skipped->value);

    Livewire::test(ListSapNotifications::class)
        ->callTableAction('retry', $record->getKey())
        ->assertOk();

    expect($record->refresh()->status)->toBe(SapNotificationStatus::Pending->value);
});
