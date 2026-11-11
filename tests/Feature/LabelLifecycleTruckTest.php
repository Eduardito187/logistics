<?php

declare(strict_types=1);

use App\Support\Cockpit\LabelLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Labels\Application\Query\ResolveLabelUnit\ResolveLabelUnit;
use Logistics\Labels\Application\ReadModel\LabelUnitView;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Ciclo de vida de la etiqueta: asignar el envío a un camión ES cargarlo. Sin escaneo de carga, la asignación
 * aparece como «Cargada» con la placa; con escaneo de carga, la placa va en ese escaneo (sin duplicar).
 */
const LLT_LABEL = 'LBLTRUCKTEST0000000000000001';
const LLT_PLATE = '4821-KTZ';
const LLT_SHIPMENT = '01a1d000-0000-7000-8000-00000000a001';
const LLT_UNIT = '01a1d000-0000-7000-8000-00000000a002';

beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        $this->markTestSkipped('Requiere PostgreSQL (base logistics_test).');
    }

    $shipmentId = LLT_SHIPMENT;
    $unitId = LLT_UNIT;

    // La etiqueta se resuelve a su bulto/envío por el QueryBus: se fija la respuesta para aislar el test.
    app()->instance(QueryBus::class, new class($shipmentId, $unitId) implements QueryBus
    {
        public function __construct(private string $shipmentId, private string $unitId) {}

        public function ask(Query $query): mixed
        {
            assert($query instanceof ResolveLabelUnit);

            return new LabelUnitView(LLT_LABEL, true, $this->unitId, $this->shipmentId, 'TV-1', 'TV', 1, 1, 1, 1, 1);
        }
    });

    DB::table('labels_emitted')->insert([
        'id' => (string) Str::uuid7(),
        'order_id' => 'SCZ-TRUCK-01',
        'label_id' => LLT_LABEL,
        'key_id' => 1,
        'zpl' => '^XA^XZ',
        'signed_json' => '{}',
        'created_at' => '2026-11-11 08:00:00-04',
    ]);

    $vehicleId = (string) Str::uuid7();
    DB::table('fleet_vehicles')->insert([
        'id' => $vehicleId,
        'plate' => LLT_PLATE,
        'capacity_kg' => 3000,
        'capacity_m3' => 12,
        'home_base_node_id' => (string) Str::uuid7(),
        'status' => 'active',
        'documents' => '[]',
    ]);

    $planId = (string) Str::uuid7();
    DB::table('planning_plans')->insert([
        'id' => $planId,
        'plan_date' => '2026-11-11',
        'vehicle_id' => $vehicleId,
        'status' => 'DRAFT',
        'created_at' => '2026-11-11 09:00:00',
        'updated_at' => '2026-11-11 09:00:00',
    ]);
    DB::table('planning_plan_stops')->insert([
        'id' => (string) Str::uuid7(),
        'plan_id' => $planId,
        'shipment_id' => $shipmentId,
        'sequence' => 1,
        'created_at' => '2026-11-11 09:15:00',
        'updated_at' => '2026-11-11 09:15:00',
    ]);
});

it('asignar el envío a un camión aparece como Cargada con la placa', function (): void {
    $timeline = app(LabelLifecycle::class)->timeline(LLT_LABEL);

    expect(array_column($timeline, 'event'))->toBe(['Cargada', 'Generada'])
        ->and($timeline[0]['detail'])->toBe('Asignada al camión '.LLT_PLATE)
        ->and($timeline[0]['when'])->toBe('11/11/2026 09:15');
});

it('con escaneo de carga, la placa va en el escaneo y no se duplica la carga', function (): void {
    DB::table('execution_scan_events')->insert([
        'id' => (string) Str::uuid7(),
        'delivery_id' => (string) Str::uuid7(),
        'unit_id' => LLT_UNIT,
        'type' => 'LOAD',
        'lat' => -17.78,
        'lng' => -63.18,
        'device_id' => 'device-test',
        'user_id' => '1',
        'occurred_at' => '2026-11-11 10:00:00-04',
        'idempotency_key' => (string) Str::uuid7(),
    ]);

    $timeline = app(LabelLifecycle::class)->timeline(LLT_LABEL);
    $loads = array_values(array_filter($timeline, static fn (array $e): bool => $e['event'] === 'Cargada'));

    expect($loads)->toHaveCount(1)
        ->and($loads[0]['detail'])->toBe('Camión '.LLT_PLATE);
});
