<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Logistics\Network\Application\Command\DefineLeg\DefineLegCommand;
use Logistics\Network\Application\Command\RegisterNode\RegisterNodeCommand;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Ejercita los controladores HTTP del contexto network (Presentation) contra las rutas reales de la
 * puerta app (lectura) e integraciones (datos maestros): alta/baja de nodos y tramos, listados con
 * filtros, fichas y corredores por departamento, más las validaciones de los FormRequest. Se autentica
 * con Sanctum y se desactiva solo el middleware de credenciales de cada puerta, igual que los tests de
 * etiquetas, para ejercitar el controlador en sí. Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    $this->withoutMiddleware([EnsureDeviceToken::class, AuthenticateServiceClient::class]);
    Sanctum::actingAs(User::factory()->create());
});

/**
 * Resuelve el UUID de un nodo ya registrado a partir de su código.
 */
function networkHttpNodeId(string $code): string
{
    /** @var NodeRepository $nodes */
    $nodes = app(NodeRepository::class);
    $node = $nodes->findByCode(NodeCode::fromString($code));

    return $node?->id()->toString() ?? throw new RuntimeException("nodo no encontrado: {$code}");
}

/**
 * Devuelve el UUID del último tramo definido.
 */
function networkHttpLastLegId(): string
{
    /** @var LegRepository $legs */
    $legs = app(LegRepository::class);
    $all = $legs->all();

    return $all[array_key_last($all)]->id()->toString();
}

it('registers a node through the integrations gate', function (): void {
    $code = 'CD-HTTP-'.random_int(1000, 9999);

    $this->postJson('/api/v1/integrations/network/nodes', [
        'code' => $code,
        'name' => 'CD HTTP',
        'type' => 'distribution_center',
        'latitude' => -16.51,
        'longitude' => -68.19,
        'department' => 'la_paz',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.code', $code);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('rejects an invalid node payload with a validation error', function (): void {
    $this->postJson('/api/v1/integrations/network/nodes', [
        'code' => '',
        'name' => 'Sin tipo',
        'type' => 'teleport',
        'latitude' => 999,
        'longitude' => -68.19,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code', 'type', 'latitude']);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lists nodes through the app gate and filters by type', function (): void {
    $code = 'CD-HTTP-'.random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand($code, 'CD HTTP', 'distribution_center', -16.51, -68.19, 'la_paz'));

    $this->getJson('/api/v1/app/network/nodes?type=distribution_center&status=active')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['count']]);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('shows a node and returns 404 for an unknown one', function (): void {
    $code = 'T-HTTP-'.random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand($code, 'Tienda HTTP', 'store', -16.49, -68.13, 'la_paz'));
    $id = networkHttpNodeId($code);

    $this->getJson("/api/v1/app/network/nodes/{$id}")
        ->assertOk()
        ->assertJsonPath('data.code', $code);

    $this->getJson('/api/v1/app/network/nodes/01920000-0000-7000-8000-000000000000')
        ->assertStatus(404)
        ->assertJsonPath('status', 404);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('defines a leg through the integrations gate and rejects an invalid payload', function (): void {
    $suffix = random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('CD-HTTP-'.$suffix, 'CD', 'distribution_center', -16.51, -68.19, 'la_paz'));
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('T-HTTP-'.$suffix, 'Tienda', 'store', -16.49, -68.13, 'la_paz'));

    $this->postJson('/api/v1/integrations/network/legs', [
        'from_node_id' => networkHttpNodeId('CD-HTTP-'.$suffix),
        'to_node_id' => networkHttpNodeId('T-HTTP-'.$suffix),
        'corridor_type' => 'cd_to_store',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.corridor_type', 'cd_to_store');

    $this->postJson('/api/v1/integrations/network/legs', [
        'from_node_id' => 'not-a-uuid',
        'to_node_id' => 'not-a-uuid',
        'corridor_type' => 'wormhole',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['from_node_id', 'to_node_id', 'corridor_type']);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lists legs through the app gate and filters by corridor', function (): void {
    $suffix = random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('CD-HTTP-'.$suffix, 'CD', 'distribution_center', -16.51, -68.19, 'la_paz'));
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('T-HTTP-'.$suffix, 'Tienda', 'store', -16.49, -68.13, 'la_paz'));
    app(CommandBus::class)->dispatch(new DefineLegCommand(
        fromNodeId: networkHttpNodeId('CD-HTTP-'.$suffix),
        toNodeId: networkHttpNodeId('T-HTTP-'.$suffix),
        corridorType: 'cd_to_store',
    ));

    $this->getJson('/api/v1/app/network/legs?corridor_type=cd_to_store')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['count']]);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('shows a leg and returns 404 for an unknown one', function (): void {
    $suffix = random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('CD-HTTP-'.$suffix, 'CD', 'distribution_center', -16.51, -68.19, 'la_paz'));
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('T-HTTP-'.$suffix, 'Tienda', 'store', -16.49, -68.13, 'la_paz'));
    app(CommandBus::class)->dispatch(new DefineLegCommand(
        fromNodeId: networkHttpNodeId('CD-HTTP-'.$suffix),
        toNodeId: networkHttpNodeId('T-HTTP-'.$suffix),
        corridorType: 'cd_to_store',
    ));
    $legId = networkHttpLastLegId();

    $this->getJson("/api/v1/app/network/legs/{$legId}")
        ->assertOk()
        ->assertJsonPath('data.corridor_type', 'cd_to_store');

    $this->getJson('/api/v1/app/network/legs/01920000-0000-7000-8000-000000000000')
        ->assertStatus(404)
        ->assertJsonPath('status', 404);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('lists operational corridors by department and requires the department', function (): void {
    $suffix = random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('CD-HTTP-'.$suffix, 'CD', 'distribution_center', -16.51, -68.19, 'la_paz'));

    $this->getJson('/api/v1/app/network/corridors?department=la_paz')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['count']]);

    $this->getJson('/api/v1/app/network/corridors')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['department']);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('removes a leg and deactivates a node through the integrations gate', function (): void {
    $suffix = random_int(1000, 9999);
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('CD-HTTP-'.$suffix, 'CD', 'distribution_center', -16.51, -68.19, 'la_paz'));
    app(CommandBus::class)->dispatch(new RegisterNodeCommand('T-HTTP-'.$suffix, 'Tienda', 'store', -16.49, -68.13, 'la_paz'));
    $cdId = networkHttpNodeId('CD-HTTP-'.$suffix);
    app(CommandBus::class)->dispatch(new DefineLegCommand(
        fromNodeId: $cdId,
        toNodeId: networkHttpNodeId('T-HTTP-'.$suffix),
        corridorType: 'cd_to_store',
    ));
    $legId = networkHttpLastLegId();

    $this->deleteJson("/api/v1/integrations/network/legs/{$legId}")->assertStatus(202);
    $this->deleteJson("/api/v1/integrations/network/nodes/{$cdId}")->assertStatus(202);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
