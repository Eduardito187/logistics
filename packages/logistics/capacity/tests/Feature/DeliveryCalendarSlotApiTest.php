<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\CapacityBucketRecord;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\DispatchBlackoutSlotRecord;
use Logistics\Coverage\Application\Contract\MunicipalityCatalog;
use Logistics\Coverage\Application\ReadModel\MunicipalityView;

/**
 * Franjas HORARIAS del calendario de entregas para la app (puerta app):
 *  - GET    /api/v1/app/delivery-calendar/day            día en detalle (bloqueo + franjas con cupo/consumo)
 *  - POST   /api/v1/app/delivery-calendar/blocked-slots  bloquea una franja (no el día)
 *  - DELETE /api/v1/app/delivery-calendar/blocked-slots  quita el bloqueo de la franja
 *  - GET    /api/v1/app/delivery-calendar                índice con capacity_total/used por día
 *
 * También verifica el enforcement: una franja bloqueada desaparece del calendario de cupos ofrecido al
 * cliente (ListCapacityCalendar). Se omite el middleware de puerta para ejercitar el contrato del
 * controller y la cadena CQRS detrás. Corre contra logistics_test; se marca skip sin PostgreSQL.
 */
const DCS_DATE = '2027-05-10';
const DCS_SLOT_AM = '08:00-12:00';
const DCS_SLOT_PM = '16:00-20:00';
const DCS_MUNI_A = '0192eeee-0000-7000-8000-0000000000a1';
const DCS_MUNI_B = '0192eeee-0000-7000-8000-0000000000a2';
const DCS_DELIVERIES = 'deliveries';
const DCS_DAY_URL = '/api/v1/app/delivery-calendar/day';
const DCS_SLOTS_URL = '/api/v1/app/delivery-calendar/blocked-slots';
const DCS_REASON = 'Fleet maintenance';

beforeEach(function (): void {
    $this->withoutMiddleware();
    dcsFakeMunicipalities();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Reemplaza el catálogo de municipios de coverage por uno en memoria con A y B (validación de existencia).
 */
function dcsFakeMunicipalities(): void
{
    $views = [
        new MunicipalityView(DCS_MUNI_A, 'Cochabamba', '0192eeee-0000-7000-8000-0000000000d1', 'Cochabamba'),
        new MunicipalityView(DCS_MUNI_B, 'Santa Cruz de la Sierra', '0192eeee-0000-7000-8000-0000000000d2', 'Santa Cruz'),
    ];

    app()->bind(MunicipalityCatalog::class, static fn (): MunicipalityCatalog => new class($views) implements MunicipalityCatalog
    {
        /** @param list<MunicipalityView> $views */
        public function __construct(private array $views) {}

        public function all(): array
        {
            return $this->views;
        }
    });
}

/**
 * Inserta un cupo de despacho por municipio en la fecha de prueba.
 */
function dcsBucket(string $municipalityId, string $timeSlot, float $total, float $used, string $unit = DCS_DELIVERIES): void
{
    CapacityBucketRecord::query()->create([
        'id' => Str::uuid()->toString(),
        'scope_kind' => 'dispatch',
        'node_id' => null,
        'zone_id' => null,
        'municipality_id' => $municipalityId,
        'warehouse_id' => null,
        'dock_id' => null,
        'slot_date' => DCS_DATE,
        'time_slot' => $timeSlot,
        'unit' => $unit,
        'total' => $total,
        'used' => $used,
    ]);
}

it('lists a municipality day slots ordered by start time with capacity, used and unit', function (): void {
    // Se insertan desordenadas a propósito para comprobar el orden por hora de inicio.
    dcsBucket(DCS_MUNI_A, DCS_SLOT_PM, 20.0, 5.0);
    dcsBucket(DCS_MUNI_A, DCS_SLOT_AM, 40.0, 10.0);

    $this->getJson(DCS_DAY_URL.'?date='.DCS_DATE.'&municipality_id='.DCS_MUNI_A)
        ->assertOk()
        ->assertJsonPath('data.date', DCS_DATE)
        ->assertJsonPath('data.blocked', false)
        ->assertJsonPath('data.slots.0.time_slot', DCS_SLOT_AM)
        ->assertJsonPath('data.slots.0.capacity', 40)
        ->assertJsonPath('data.slots.0.used', 10)
        ->assertJsonPath('data.slots.0.unit', DCS_DELIVERIES)
        ->assertJsonPath('data.slots.0.blocked', false)
        ->assertJsonPath('data.slots.1.time_slot', DCS_SLOT_PM)
        ->assertJsonPath('data.slots.1.capacity', 20);
});

it('consolidates slots globally across municipalities when no municipality is given', function (): void {
    dcsBucket(DCS_MUNI_A, DCS_SLOT_AM, 40.0, 10.0);
    dcsBucket(DCS_MUNI_B, DCS_SLOT_AM, 30.0, 5.0);

    $response = $this->getJson(DCS_DAY_URL.'?date='.DCS_DATE)->assertOk();

    // Una sola franja consolidada: suma de cupo y consumo de ambos municipios.
    expect($response->json('data.slots'))->toHaveCount(1);
    $response
        ->assertJsonPath('data.slots.0.time_slot', DCS_SLOT_AM)
        ->assertJsonPath('data.slots.0.capacity', 70)
        ->assertJsonPath('data.slots.0.used', 15);
});

it('marks every slot blocked when the whole day is blocked', function (): void {
    dcsBucket(DCS_MUNI_A, DCS_SLOT_AM, 40.0, 10.0);

    $this->postJson('/api/v1/app/delivery-calendar/blocked', [
        'date' => DCS_DATE,
        'municipality_id' => DCS_MUNI_A,
        'reason' => 'National holiday',
    ])->assertStatus(201);

    $this->getJson(DCS_DAY_URL.'?date='.DCS_DATE.'&municipality_id='.DCS_MUNI_A)
        ->assertOk()
        ->assertJsonPath('data.blocked', true)
        ->assertJsonPath('data.reason', 'National holiday')
        ->assertJsonPath('data.slots.0.blocked', true)
        ->assertJsonPath('data.slots.0.reason', 'National holiday');
});

it('blocks a single slot idempotently and marks only that slot', function (): void {
    dcsBucket(DCS_MUNI_A, DCS_SLOT_AM, 40.0, 10.0);
    dcsBucket(DCS_MUNI_A, DCS_SLOT_PM, 20.0, 5.0);

    $payload = ['date' => DCS_DATE, 'time_slot' => DCS_SLOT_AM, 'reason' => DCS_REASON, 'municipality_id' => DCS_MUNI_A];
    $this->postJson(DCS_SLOTS_URL, $payload)->assertStatus(202)->assertJsonPath('data.blocked', true);
    // Re-bloquear no duplica la fila (idempotencia por clave natural).
    $this->postJson(DCS_SLOTS_URL, $payload)->assertStatus(202);

    expect(DispatchBlackoutSlotRecord::query()->count())->toBe(1);

    $response = $this->getJson(DCS_DAY_URL.'?date='.DCS_DATE.'&municipality_id='.DCS_MUNI_A)->assertOk();
    $slots = collect($response->json('data.slots'))->keyBy('time_slot');
    expect($slots[DCS_SLOT_AM]['blocked'])->toBeTrue()
        ->and($slots[DCS_SLOT_AM]['reason'])->toBe(DCS_REASON)
        ->and($slots[DCS_SLOT_PM]['blocked'])->toBeFalse();
});

it('removes a slot block and is idempotent on delete', function (): void {
    dcsBucket(DCS_MUNI_A, DCS_SLOT_AM, 40.0, 10.0);

    $this->postJson(DCS_SLOTS_URL, [
        'date' => DCS_DATE, 'time_slot' => DCS_SLOT_AM, 'reason' => DCS_REASON, 'municipality_id' => DCS_MUNI_A,
    ])->assertStatus(202);

    $delete = ['date' => DCS_DATE, 'time_slot' => DCS_SLOT_AM, 'municipality_id' => DCS_MUNI_A];
    $this->deleteJson(DCS_SLOTS_URL, $delete)->assertStatus(204);
    expect(DispatchBlackoutSlotRecord::query()->count())->toBe(0);

    // La franja vuelve a estar disponible.
    $this->getJson(DCS_DAY_URL.'?date='.DCS_DATE.'&municipality_id='.DCS_MUNI_A)
        ->assertOk()
        ->assertJsonPath('data.slots.0.blocked', false);

    // Borrar de nuevo (ya no existe) responde 204 igual.
    $this->deleteJson(DCS_SLOTS_URL, $delete)->assertStatus(204);
});

it('hides a blocked slot from the capacity calendar offered to the client', function (): void {
    dcsBucket(DCS_MUNI_A, DCS_SLOT_AM, 40.0, 10.0);
    dcsBucket(DCS_MUNI_A, DCS_SLOT_PM, 20.0, 5.0);

    $url = '/api/v1/app/capacity/calendar?date='.DCS_DATE.'&municipality_id='.DCS_MUNI_A;
    expect($this->getJson($url)->assertOk()->json('data'))->toHaveCount(2);

    $this->postJson(DCS_SLOTS_URL, [
        'date' => DCS_DATE, 'time_slot' => DCS_SLOT_AM, 'reason' => DCS_REASON, 'municipality_id' => DCS_MUNI_A,
    ])->assertStatus(202);

    // Tras bloquear la franja AM, el calendario del cliente solo ofrece la PM.
    $data = $this->getJson($url)->assertOk()->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['time_slot'])->toBe(DCS_SLOT_PM);
});

it('rejects a malformed time slot with 422', function (): void {
    // Sin guion válido (no es HH:MM-HH:MM).
    $this->postJson(DCS_SLOTS_URL, [
        'date' => DCS_DATE, 'time_slot' => '0800-1200', 'reason' => DCS_REASON, 'municipality_id' => DCS_MUNI_A,
    ])->assertStatus(422)->assertJsonPath('code', 'business_rule_violation');

    // Inicio no anterior al fin.
    $this->postJson(DCS_SLOTS_URL, [
        'date' => DCS_DATE, 'time_slot' => '12:00-08:00', 'reason' => DCS_REASON, 'municipality_id' => DCS_MUNI_A,
    ])->assertStatus(422);

    expect(DispatchBlackoutSlotRecord::query()->count())->toBe(0);
});

it('rejects a nonexistent municipality with 422', function (): void {
    $this->postJson(DCS_SLOTS_URL, [
        'date' => DCS_DATE,
        'time_slot' => DCS_SLOT_AM,
        'reason' => DCS_REASON,
        'municipality_id' => '0192eeee-0000-7000-8000-0000000000ff',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation');

    expect(DispatchBlackoutSlotRecord::query()->count())->toBe(0);
});

it('includes capacity_total and capacity_used per day in the index', function (): void {
    dcsBucket(DCS_MUNI_A, DCS_SLOT_AM, 40.0, 10.0);
    dcsBucket(DCS_MUNI_A, DCS_SLOT_PM, 20.0, 5.0);

    $this->getJson('/api/v1/app/delivery-calendar?from='.DCS_DATE.'&to='.DCS_DATE.'&municipality_id='.DCS_MUNI_A)
        ->assertOk()
        ->assertJsonPath('data.days.0.date', DCS_DATE)
        ->assertJsonPath('data.days.0.capacity_total', 60)
        ->assertJsonPath('data.days.0.capacity_used', 15);
});
