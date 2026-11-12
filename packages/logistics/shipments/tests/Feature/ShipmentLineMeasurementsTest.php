<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Shipments\Domain\Contract\ShipmentRepository;
use Logistics\Shipments\Domain\ValueObject\MeasurementsSource;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\SourceReference;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentLineRecord;

/**
 * Falla al sembrar: el envío recién recibido no apareció en el repositorio.
 */
final class LineMeasurementsShipmentNotFound extends RuntimeException {}

/**
 * Foto de medidas por línea al recibir el envío (Roadmap 02, Fase 2.1) y su backfill, contra PostgreSQL
 * real (logistics_test). El catálogo se siembra por SQL directo (shipments no depende del dominio de
 * commerce/catalog: solo lee sus tablas).
 */
beforeEach(function (): void {
    config()->set('logistics-shipments.measurements.default_unit_weight_kg', 10.0);
    config()->set('logistics-shipments.measurements.default_unit_volume_m3', 0.05);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra un producto del catálogo con medidas (y opcionalmente su categoría con defaults).
 *
 * @param array<string, float|null> $measures
 * @param array<string, float|null> $categoryDefaults
 */
function seedCatalogProductMeasures(string $sku, array $measures, ?array $categoryDefaults = null): void
{
    $categoryId = null;
    if ($categoryDefaults !== null) {
        $categoryId = Uuid::generate()->value;
        DB::table('commerce_categories')->insert([
            'id' => $categoryId,
            'name' => 'Categoría '.$sku,
            'slug' => 'cat-'.strtolower($sku).'-'.substr($categoryId, -6),
            ...$categoryDefaults,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('commerce_products')->insert([
        'id' => Uuid::generate()->value,
        'category_id' => $categoryId,
        'name' => 'Producto '.$sku,
        'canonical_sku' => strtoupper($sku),
        'status' => 'published',
        'variants' => '[]',
        'media' => '[]',
        ...$measures,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Recibe un pedido por el caso de uso de ingreso y devuelve sus líneas persistidas indexadas por SKU.
 *
 * @param  list<ShipmentLineInput>           $lines
 * @return array<string, ShipmentLineRecord>
 */
function receiveAndLoadLines(string $externalId, array $lines): array
{
    app(CommandBus::class)->dispatch(new ReceiveShipment(
        source: 'STORE',
        externalId: $externalId,
        customerName: 'Cliente Medidas',
        addressText: 'Av. Banzer 123, Santa Cruz',
        addressReference: null,
        lat: -17.78,
        lng: -63.18,
        lines: $lines,
    ));

    $shipment = app(ShipmentRepository::class)->findBySourceReference(
        SourceReference::of(OrderSource::Store, $externalId),
    ) ?? throw new LineMeasurementsShipmentNotFound('envío no encontrado');

    $records = ShipmentLineRecord::query()->where('shipment_id', $shipment->id()->toString())->get();

    $bySku = [];
    foreach ($records as $record) {
        $bySku[$record->sku] = $record;
    }

    return $bySku;
}

it('la foto usa las medidas del producto del catálogo (fuente product)', function (): void {
    seedCatalogProductMeasures('LAV-MED-1', [
        'weight_kg' => 65.5, 'length_cm' => 60.0, 'width_cm' => 65.0, 'height_cm' => 85.0,
    ]);

    // El SKU de la fuente viene en minúsculas: el cruce con el catálogo se normaliza.
    $lines = receiveAndLoadLines('MED-'.uniqid(), [new ShipmentLineInput('lav-med-1', 'Lavadora', 2)]);

    $line = $lines['lav-med-1'];
    expect((float) $line->unit_weight_kg)->toBe(65.5)
        ->and((float) $line->unit_volume_m3)->toBe(0.3315)
        ->and($line->measurements_source)->toBe('product');
});

it('cae a los defaults de la categoría cuando el producto no tiene medidas', function (): void {
    seedCatalogProductMeasures('COC-MED-1', [], [
        'weight_kg' => 40.0, 'length_cm' => 60.0, 'width_cm' => 60.0, 'height_cm' => 90.0,
    ]);

    $lines = receiveAndLoadLines('MED-'.uniqid(), [new ShipmentLineInput('COC-MED-1', 'Cocina', 1)]);

    $line = $lines['COC-MED-1'];
    expect((float) $line->unit_weight_kg)->toBe(40.0)
        ->and((float) $line->unit_volume_m3)->toBe(0.324)
        ->and($line->measurements_source)->toBe('category');
});

it('sin medidas en el catálogo cae al default global con fuente default y nunca falla', function (): void {
    $lines = receiveAndLoadLines('MED-'.uniqid(), [
        new ShipmentLineInput('SKU-SIN-CATALOGO', 'Producto sin ficha', 3),
    ]);

    $line = $lines['SKU-SIN-CATALOGO'];
    expect((float) $line->unit_weight_kg)->toBe(10.0)
        ->and((float) $line->unit_volume_m3)->toBe(0.05)
        ->and($line->measurements_source)->toBe('default');

    // El evento de recepción lleva la carga agregada y el flag de dato incompleto.
    $event = DB::table('outbox_messages')
        ->where('event_name', 'shipments.shipment_received')
        ->where('aggregate_id', $line->shipment_id)
        ->first();
    expect($event)->not->toBeNull();
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $event->payload, true);
    expect((float) $payload['total_weight_kg'])->toBe(30.0)
        ->and($payload['measurements_defaulted'])->toBeTrue();
});

it('la foto no se recalcula: re-guardar el envío conserva las medidas aunque cambie el catálogo', function (): void {
    seedCatalogProductMeasures('TV-MED-1', [
        'weight_kg' => 18.0, 'length_cm' => 130.0, 'width_cm' => 15.0, 'height_cm' => 80.0,
    ]);
    $externalId = 'MED-'.uniqid();
    receiveAndLoadLines($externalId, [new ShipmentLineInput('TV-MED-1', 'Televisor', 1)]);

    DB::table('commerce_products')->where('canonical_sku', 'TV-MED-1')->update(['weight_kg' => 99.0]);

    $repository = app(ShipmentRepository::class);
    $shipment = $repository->findBySourceReference(SourceReference::of(OrderSource::Store, $externalId));
    expect($shipment)->not->toBeNull();
    $repository->save($shipment);

    $reloaded = $repository->findBySourceReference(SourceReference::of(OrderSource::Store, $externalId));
    $measure = $reloaded?->lines()[0]->measure();
    expect($measure?->unitWeightKg)->toBe(18.0)
        ->and($measure?->unitVolumeM3)->toBe(0.156)
        ->and($measure?->source)->toBe(MeasurementsSource::Product);
});

it('el backfill completa solo los nulls y es idempotente', function (): void {
    seedCatalogProductMeasures('BKF-MED-1', [
        'weight_kg' => 7.0, 'length_cm' => 20.0, 'width_cm' => 20.0, 'height_cm' => 20.0,
    ]);

    // Línea histórica sin foto, de un SKU con medidas en el catálogo.
    $empty = ShipmentLineRecord::factory()->create(['sku' => 'BKF-MED-1', 'quantity' => 1]);
    // Línea histórica sin foto, de un SKU desconocido.
    $unknown = ShipmentLineRecord::factory()->create(['sku' => 'BKF-DESCONOCIDO', 'quantity' => 1]);
    // Línea con foto parcial: el peso existente NO se pisa.
    $partial = ShipmentLineRecord::factory()->create([
        'sku' => 'BKF-MED-1',
        'quantity' => 1,
        'unit_weight_kg' => 1.5,
    ]);
    // Línea con foto completa: no se toca.
    $complete = ShipmentLineRecord::factory()->withMeasurements(3.0, 0.02, 'category')->create([
        'sku' => 'BKF-MED-1',
    ]);

    $this->artisan('shipments:backfill-line-measurements', ['--chunk' => 2])->assertSuccessful();

    $empty->refresh();
    expect((float) $empty->unit_weight_kg)->toBe(7.0)
        ->and((float) $empty->unit_volume_m3)->toBe(0.008)
        ->and($empty->measurements_source)->toBe('product');

    $unknown->refresh();
    expect((float) $unknown->unit_weight_kg)->toBe(10.0)
        ->and((float) $unknown->unit_volume_m3)->toBe(0.05)
        ->and($unknown->measurements_source)->toBe('default');

    $partial->refresh();
    expect((float) $partial->unit_weight_kg)->toBe(1.5)
        ->and((float) $partial->unit_volume_m3)->toBe(0.008)
        ->and($partial->measurements_source)->toBe('product');

    $complete->refresh();
    expect((float) $complete->unit_weight_kg)->toBe(3.0)
        ->and((float) $complete->unit_volume_m3)->toBe(0.02)
        ->and($complete->measurements_source)->toBe('category');

    // Segunda corrida: no queda nada pendiente.
    $pending = ShipmentLineRecord::query()
        ->where(function ($query): void {
            $query->whereNull('unit_weight_kg')
                ->orWhereNull('unit_volume_m3')
                ->orWhereNull('measurements_source');
        })
        ->count();
    expect($pending)->toBe(0);

    $this->artisan('shipments:backfill-line-measurements')->assertSuccessful();
    $complete->refresh();
    expect($complete->measurements_source)->toBe('category');
});
