<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// Admin en la app: reprogramar pedidos en lote (por camión / ciudad / lista) con vista previa, respetando las
// fechas bloqueadas, y manejar el calendario de despacho (ver, bloquear, desbloquear).
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function arcActingAs(Role $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Un camión con dos pedidos abiertos y uno ya entregado. Devuelve [vehicleId, abiertos, entregado].
 *
 * @return array{0: string, 1: list<string>, 2: string}
 */
function arcTruck(): array
{
    $vehicleId = (string) Str::uuid7();
    $open = [];
    foreach (['PLANNED', 'IN_EXECUTION'] as $status) {
        $shipment = ShipmentRecord::factory()->create(['status' => $status, 'delivery_date' => '2026-12-01', 'delivery_window_start' => '09:00', 'delivery_window_end' => '12:00']);
        $open[] = (string) $shipment->id;
        DB::table('execution_deliveries')->insert(['id' => (string) Str::uuid7(), 'shipment_id' => $shipment->id, 'vehicle_id' => $vehicleId, 'status' => 'LOADED']);
    }
    $delivered = ShipmentRecord::factory()->create(['status' => 'DELIVERED']);
    DB::table('execution_deliveries')->insert(['id' => (string) Str::uuid7(), 'shipment_id' => $delivered->id, 'vehicle_id' => $vehicleId, 'status' => 'DELIVERED']);

    return [$vehicleId, $open, (string) $delivered->id];
}

/**
 * @return array<string, mixed>
 */
function arcBody(string $vehicleId, string $newDate = '2027-01-15'): array
{
    return [
        'scope' => ['vehicle_id' => $vehicleId],
        'new_date' => $newDate,
        'window_start' => '14:00',
        'window_end' => '18:00',
        'reason' => 'Camión en mantenimiento',
    ];
}

it('la vista previa muestra el alcance sin cambiar nada', function (): void {
    arcActingAs(Role::Supervisor);
    [$vehicleId, $open, $delivered] = arcTruck();

    $this->postJson('/api/v1/app/shipments/reschedule/preview', arcBody($vehicleId))
        ->assertOk()
        ->assertJsonPath('data.applied', false)
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.skipped_count', 1)
        ->assertJsonPath('data.skipped.0.shipment_id', $delivered);

    expect(ShipmentRecord::query()->whereIn('id', $open)->pluck('delivery_date')->unique()->count())->toBe(1)
        ->and((string) ShipmentRecord::query()->findOrFail($open[0])->delivery_date)->toStartWith('2026-12-01');
});

it('el admin reprograma los pedidos abiertos del camión y deja auditoría', function (): void {
    arcActingAs(Role::Admin);
    [$vehicleId, $open, $delivered] = arcTruck();

    $this->postJson('/api/v1/app/shipments/reschedule', arcBody($vehicleId))
        ->assertOk()
        ->assertJsonPath('data.applied', true)
        ->assertJsonPath('data.count', 2)
        ->assertJsonPath('data.failed', []);

    foreach ($open as $id) {
        expect((string) ShipmentRecord::query()->findOrFail($id)->delivery_date)->toStartWith('2027-01-15');
    }

    expect(DB::table('outbox_messages')->where('event_name', 'shipments.shipment_rescheduled')->whereIn('aggregate_id', $open)->count())->toBe(2)
        ->and(ShipmentRecord::query()->findOrFail($delivered)->status)->toBe('DELIVERED')
        ->and(DB::table('access_audit_log')->where('action', 'shipments.rescheduled_in_bulk')->exists())->toBeTrue();
});

it('aplicar es solo de admin y un chofer no ve ni la vista previa', function (): void {
    [$vehicleId] = arcTruck();

    arcActingAs(Role::Supervisor);
    $this->postJson('/api/v1/app/shipments/reschedule', arcBody($vehicleId))->assertStatus(403);

    arcActingAs(Role::Chofer);
    $this->postJson('/api/v1/app/shipments/reschedule/preview', arcBody($vehicleId))->assertStatus(403);
});

it('exige exactamente un alcance', function (): void {
    arcActingAs(Role::Admin);
    [$vehicleId] = arcTruck();

    $body = arcBody($vehicleId);
    $body['scope'] = ['vehicle_id' => $vehicleId, 'city' => 'Santa Cruz'];

    $this->postJson('/api/v1/app/shipments/reschedule/preview', $body)->assertStatus(422)->assertJsonPath('code', 'invalid_scope');
    $this->postJson('/api/v1/app/shipments/reschedule/preview', ['scope' => []] + arcBody($vehicleId))->assertStatus(422);
});

it('bloquea un día en el calendario, lo muestra, rechaza reprogramar a ese día y lo desbloquea', function (): void {
    arcActingAs(Role::Admin);
    [$vehicleId] = arcTruck();

    $this->postJson('/api/v1/app/delivery-calendar/blocked', ['date' => '2027-02-10', 'reason' => 'Feriado nacional'])
        ->assertStatus(201);

    $calendar = $this->getJson('/api/v1/app/delivery-calendar?from=2027-02-09&to=2027-02-11')->assertOk();
    $days = collect($calendar->json('data.days'))->keyBy('date');

    expect($calendar->json('data.days'))->toHaveCount(3)
        ->and($days['2027-02-10']['blocked'])->toBeTrue()
        ->and($days['2027-02-10']['available'])->toBeFalse()
        ->and($days['2027-02-09']['blocked'])->toBeFalse();

    $this->postJson('/api/v1/app/shipments/reschedule/preview', arcBody($vehicleId, '2027-02-10'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'date_blocked');

    $this->deleteJson('/api/v1/app/delivery-calendar/blocked', ['date' => '2027-02-10'])->assertOk();

    $this->postJson('/api/v1/app/shipments/reschedule/preview', arcBody($vehicleId, '2027-02-10'))->assertOk();
});
