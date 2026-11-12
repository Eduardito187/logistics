<?php

declare(strict_types=1);

use Commerce\Catalog\Application\Command\CreateCategory\CreateCategory;
use Commerce\Catalog\Application\Command\DraftProduct\DraftProduct;
use Commerce\Catalog\Application\Command\SetCategoryPhysicalDefaults\SetCategoryPhysicalDefaults;
use Commerce\Catalog\Application\Command\SetProductPhysicalSpec\SetProductPhysicalSpec;
use Commerce\Catalog\Application\Exception\ProductNotFound;
use Commerce\Catalog\Application\Query\GetProductBySku\GetProductBySku;
use Commerce\Catalog\Application\ReadModel\ProductView;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryRecord;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\ProductRecord;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Medidas físicas del catálogo (Roadmap 02, Fase 2.0) contra PostgreSQL real (logistics_test):
 * comando SetProductPhysicalSpec y endpoints de integraciones por SKU (PUT individual y POST en lote).
 *
 * Se omite el middleware de puerta (service.auth/idempotency) para ejercitar el contrato del controller,
 * su FormRequest y la cadena CQRS detrás.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('upsert por SKU: PUT guarda las medidas, devuelve el volumen y es idempotente', function (): void {
    $this->commands->dispatch(new DraftProduct('Lavadora 10kg', 'lav-10'));

    $this->putJson('/api/v1/integrations/catalog/products/LAV-10/physical-spec', [
        'weight_kg' => 65.5,
        'length_cm' => 60,
        'width_cm' => 65,
        'height_cm' => 85,
    ])->assertOk()
        ->assertJsonPath('data.canonical_sku', 'LAV-10')
        ->assertJsonPath('data.physical_spec.weight_kg', 65.5)
        ->assertJsonPath('data.physical_spec.volume_m3', 0.3315);

    $record = ProductRecord::query()->where('canonical_sku', 'LAV-10')->firstOrFail();
    expect((float) $record->weight_kg)->toBe(65.5)
        ->and((float) $record->height_cm)->toBe(85.0);

    // Reenviar la misma ficha no graba un segundo evento de cambio.
    $this->putJson('/api/v1/integrations/catalog/products/LAV-10/physical-spec', [
        'weight_kg' => 65.5,
        'length_cm' => 60,
        'width_cm' => 65,
        'height_cm' => 85,
    ])->assertOk();

    $events = DB::table('outbox_messages')
        ->where('event_name', 'catalog.product_physical_spec_changed')
        ->where('aggregate_id', $record->id)
        ->count();
    expect($events)->toBe(1);
});

it('PUT acepta una ficha parcial (solo peso) sin volumen', function (): void {
    $this->commands->dispatch(new DraftProduct('Microondas', 'mic-01'));

    $this->putJson('/api/v1/integrations/catalog/products/mic-01/physical-spec', [
        'weight_kg' => 12,
    ])->assertOk()
        ->assertJsonPath('data.physical_spec.weight_kg', 12)
        ->assertJsonPath('data.physical_spec.length_cm', null)
        ->assertJsonPath('data.physical_spec.volume_m3', null);
});

it('PUT rechaza con 422 valores inválidos y un cuerpo vacío', function (): void {
    $this->commands->dispatch(new DraftProduct('Cocina', 'coc-01'));

    $this->putJson('/api/v1/integrations/catalog/products/COC-01/physical-spec', ['weight_kg' => -3])
        ->assertStatus(422);
    $this->putJson('/api/v1/integrations/catalog/products/COC-01/physical-spec', ['weight_kg' => 0])
        ->assertStatus(422);
    $this->putJson('/api/v1/integrations/catalog/products/COC-01/physical-spec', ['length_cm' => 'ancho'])
        ->assertStatus(422);
    $this->putJson('/api/v1/integrations/catalog/products/COC-01/physical-spec', ['height_cm' => 999999])
        ->assertStatus(422);
    $this->putJson('/api/v1/integrations/catalog/products/COC-01/physical-spec', [])
        ->assertStatus(422);

    $record = ProductRecord::query()->where('canonical_sku', 'COC-01')->firstOrFail();
    expect($record->weight_kg)->toBeNull();
});

it('PUT devuelve 404 si el SKU no existe', function (): void {
    $this->putJson('/api/v1/integrations/catalog/products/NO-EXISTE/physical-spec', ['weight_kg' => 5])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

it('POST en lote aplica cada SKU por separado e informa los no encontrados', function (): void {
    $this->commands->dispatch(new DraftProduct('Televisor 55', 'tv-55'));
    $this->commands->dispatch(new DraftProduct('Televisor 65', 'tv-65'));

    $this->postJson('/api/v1/integrations/catalog/products/physical-specs', [
        'items' => [
            ['sku' => 'TV-55', 'weight_kg' => 18, 'length_cm' => 130, 'width_cm' => 15, 'height_cm' => 80],
            ['sku' => 'tv-65', 'weight_kg' => 25],
            ['sku' => 'NO-EXISTE', 'weight_kg' => 1],
        ],
    ])->assertOk()
        ->assertJsonPath('data.summary.updated', 2)
        ->assertJsonPath('data.summary.not_found', 1)
        ->assertJsonPath('data.results.2.status', 'not_found');

    /** @var ProductView $tv55 */
    $tv55 = $this->queries->ask(new GetProductBySku('TV-55'));
    expect($tv55->physicalSpec['volume_m3'])->toBe(0.156);

    /** @var ProductView $tv65 */
    $tv65 = $this->queries->ask(new GetProductBySku('TV-65'));
    expect($tv65->physicalSpec['weight_kg'])->toBe(25.0);
});

it('POST en lote rechaza con 422 un ítem con valores inválidos o un lote vacío', function (): void {
    $this->postJson('/api/v1/integrations/catalog/products/physical-specs', [
        'items' => [
            ['sku' => 'TV-55', 'weight_kg' => -1],
        ],
    ])->assertStatus(422);

    $this->postJson('/api/v1/integrations/catalog/products/physical-specs', ['items' => []])
        ->assertStatus(422);
});

it('el comando por id lanza ProductNotFound si el producto no existe', function (): void {
    expect(fn () => $this->commands->dispatch(new SetProductPhysicalSpec(
        productId: '01900000-0000-7000-8000-000000000000',
        weightKg: 1.0,
    )))->toThrow(ProductNotFound::class);
});

it('la categoría guarda sus medidas por defecto', function (): void {
    $this->commands->dispatch(new CreateCategory('Línea blanca', 'linea-blanca'));
    $category = CategoryRecord::query()->where('slug', 'linea-blanca')->firstOrFail();

    $this->commands->dispatch(new SetCategoryPhysicalDefaults(
        categoryId: (string) $category->id,
        weightKg: 45.0,
        lengthCm: 70.0,
        widthCm: 70.0,
        heightCm: 100.0,
    ));

    $category->refresh();
    expect((float) $category->weight_kg)->toBe(45.0)
        ->and((float) $category->height_cm)->toBe(100.0);
});
