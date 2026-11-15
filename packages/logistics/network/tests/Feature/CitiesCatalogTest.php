<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

// Catálogo de ciudades de la app (GET /api/v1/app/network/cities): los nueve departamentos siempre, con el
// almacén central de cada uno si lo tiene. Para cualquier rol autenticado.
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function citiesCatalogNode(string $code, string $type, string $department, string $status = 'active'): string
{
    $id = (string) Str::uuid7();
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => $code,
        'name' => 'Nodo '.$code,
        'type' => $type,
        'lat' => -11.0,
        'lng' => -68.7,
        'status' => $status,
        'department' => $department,
    ]);

    return $id;
}

it('lista los nueve departamentos con su almacén central para cualquier rol', function (): void {
    $user = User::factory()->create();
    $user->assignRole(Role::Preparador);
    Sanctum::actingAs($user);

    // Se parte de lo que ya haya en la base de test (normalmente nada) para no depender de otros datos.
    $before = collect($this->getJson('/api/v1/app/network/cities')->assertOk()->json('data'))->keyBy('code');

    citiesCatalogNode('ZZ-PND-02', 'distribution_center', 'pando');
    $first = citiesCatalogNode('ZZ-PND-01', 'central_warehouse', 'pando');
    citiesCatalogNode('ZZ-PND-00', 'distribution_center', 'pando', 'inactive');
    citiesCatalogNode('ZZ-PND-T1', 'store', 'pando');
    citiesCatalogNode('ZZ-BEN-T1', 'store', 'beni');

    $response = $this->getJson('/api/v1/app/network/cities')
        ->assertOk()
        ->assertJsonCount(9, 'data')
        ->assertJsonStructure(['data' => [['code', 'name', 'central_warehouse', 'nodes_count']]]);

    $cities = collect($response->json('data'))->keyBy('code');

    expect($cities->keys()->all())->toBe(['la_paz', 'santa_cruz', 'cochabamba', 'oruro', 'potosi', 'chuquisaca', 'tarija', 'beni', 'pando'])
        ->and($cities['potosi']['name'])->toBe('Potosí')
        ->and($cities['pando']['nodes_count'])->toBe($before['pando']['nodes_count'] + 3)
        ->and($cities['beni']['nodes_count'])->toBe($before['beni']['nodes_count'] + 1);

    // Con la base de test limpia: Pando toma el primer almacén ACTIVO por código y Beni no tiene ninguno.
    if ($before['pando']['central_warehouse'] === null && $before['beni']['central_warehouse'] === null) {
        expect($cities['pando']['central_warehouse'])->toBe(['node_id' => $first, 'code' => 'ZZ-PND-01', 'name' => 'Nodo ZZ-PND-01'])
            ->and($cities['beni']['central_warehouse'])->toBeNull();
    }
});

it('exige sesión para ver el catálogo de ciudades', function (): void {
    $this->getJson('/api/v1/app/network/cities')->assertStatus(401);
});
