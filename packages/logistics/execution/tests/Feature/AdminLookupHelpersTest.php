<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Execution\Presentation\Filament\Support\NodeNames;
use Logistics\Execution\Presentation\Filament\Support\UnitNames;
use Logistics\Execution\Presentation\Filament\Support\UserNames;

/**
 * Cobertura de los helpers de presentación del admin que resuelven FK→valor legible (regla: ningún UUID
 * visible). El foco es la preparación para Octane: ahora cachean en Valkey con TTL corto en vez de una
 * propiedad estática que persistiría entre requests del worker. Se verifica que label() sigue devolviendo
 * el nombre legible y null/placeholder para ids inexistentes, y que se apoyan en el caché.
 */
beforeEach(function (): void {
    // Caché limpio por test: el store array persiste entre tests del mismo proceso (como el worker Octane).
    Cache::flush();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('UserNames resuelve el nombre por id y oculta/placeholder lo no resuelto', function (): void {
    $user = User::factory()->create(['name' => 'Ana Operadora']);

    expect(UserNames::label((string) $user->getKey()))->toBe('Ana Operadora')
        ->and(UserNames::label(null))->toBe('—')
        ->and(UserNames::label(''))->toBe('—')
        // UUID no resuelto: nunca se expone crudo.
        ->and(UserNames::label((string) Str::uuid7()))->toBe('—')
        // Código legible no resuelto: se muestra tal cual.
        ->and(UserNames::label('OP-123'))->toBe('OP-123');
});

it('UserNames cachea el mapa bajo la clave estable admin:user-names', function (): void {
    User::factory()->create(['name' => 'Beto']);

    UserNames::label('x'); // fuerza la carga del mapa

    expect(Cache::has('admin:user-names'))->toBeTrue();
});

it('NodeNames resuelve el nombre de un nodo por id y null si no existe', function (): void {
    $id = (string) Str::uuid7();
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'CD-01',
        'name' => 'Centro de distribución',
        'type' => 'warehouse',
        'lat' => 0,
        'lng' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(NodeNames::label($id))->toBe('Centro de distribución')
        ->and(NodeNames::label(null))->toBeNull()
        ->and(NodeNames::label(''))->toBeNull()
        ->and(NodeNames::label((string) Str::uuid7()))->toBeNull()
        ->and(Cache::has('admin:node-names:execution'))->toBeTrue();
});

it('UnitNames resuelve la etiqueta de producto por unit_id y nunca expone el UUID crudo', function (): void {
    $lineId = (string) Str::uuid7();
    $unitId = (string) Str::uuid7();
    $shipmentId = (string) Str::uuid7();

    DB::table('shipment_lines')->insert([
        'id' => $lineId,
        'shipment_id' => $shipmentId,
        'sku' => 'SKU-TV-55',
        'product_name' => 'Televisor LED 55"',
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('shipment_units')->insert([
        'id' => $unitId,
        'shipment_id' => $shipmentId,
        'line_id' => $lineId,
        'unit_index' => 1,
        'unit_total' => 1,
        'piece_index' => 1,
        'piece_total' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(UnitNames::label($unitId))->toBe('Televisor LED 55" · SKU-TV-55')
        // UUID que no resuelve a un producto conocido → texto genérico, nunca el id crudo.
        ->and(UnitNames::label((string) Str::uuid7()))->toBe('Producto')
        // Código de etiqueta legible (no UUID) → se muestra tal cual.
        ->and(UnitNames::label('U-10001'))->toBe('U-10001')
        ->and(UnitNames::label(null))->toBe('Producto')
        ->and(UnitNames::label(''))->toBe('Producto')
        // Se cachea por id (no acumula en una estática del proceso).
        ->and(Cache::has('admin:unit-name:'.$unitId))->toBeTrue();
});
