<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\Query\ListShipments\ListShipmentsHandler;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

/**
 * Alcance por tienda del listado de envíos (filtro por nodo destino).
 *
 * (a) El Query ListShipments filtra por destinationNodeId. (b) La puerta app fuerza, para el rol Tienda,
 * el filtro a su nodo asignado: solo ve SUS envíos y nunca los de otra tienda. Contra PostgreSQL
 * (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('filtra el listado por nodo destino en el Query ListShipments', function (): void {
    $nodeA = Uuid::generate()->value;
    $nodeB = Uuid::generate()->value;

    ShipmentRecord::factory()->forNode($nodeA)->create();
    ShipmentRecord::factory()->forNode($nodeA)->create();
    ShipmentRecord::factory()->forNode($nodeB)->create();
    ShipmentRecord::factory()->create(); // sin nodo destino (null)

    $handler = app(ListShipmentsHandler::class);

    $forNodeA = $handler->handle(new ListShipments(destinationNodeId: $nodeA));
    expect($forNodeA)->toHaveCount(2)
        ->and($forNodeA)->each->toBeInstanceOf(ShipmentView::class);
    foreach ($forNodeA as $view) {
        expect($view->destinationNodeId)->toBe($nodeA);
    }

    // Sin filtro: devuelve los cuatro (dos de A, uno de B, uno sin nodo).
    expect($handler->handle(new ListShipments))->toHaveCount(4);
});

it('acota /app/shipments al nodo de la tienda y oculta los de otro nodo', function (): void {
    $ownNode = Uuid::generate()->value;
    $otherNode = Uuid::generate()->value;

    $mine = ShipmentRecord::factory()->forNode($ownNode)->create();
    ShipmentRecord::factory()->forNode($otherNode)->create();
    ShipmentRecord::factory()->create(); // sin nodo: tampoco debe verse

    $user = User::factory()->create();
    $user->assignRole(Role::Tienda);
    $user->forceFill(['node_id' => $ownNode])->save();
    test()->actingAs($user, 'sanctum');

    test()->getJson('/api/v1/app/shipments')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.destination_node_id', $ownNode);
});

it('devuelve lista vacía a una tienda sin nodo asignado', function (): void {
    ShipmentRecord::factory()->forNode(Uuid::generate()->value)->create();

    $user = User::factory()->create();
    $user->assignRole(Role::Tienda);
    $user->forceFill(['node_id' => null])->save();
    test()->actingAs($user, 'sanctum');

    test()->getJson('/api/v1/app/shipments')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('no acota a un supervisor: ve envíos de cualquier nodo', function (): void {
    ShipmentRecord::factory()->forNode(Uuid::generate()->value)->create();
    ShipmentRecord::factory()->forNode(Uuid::generate()->value)->create();

    $user = User::factory()->create();
    $user->assignRole(Role::Supervisor);
    $user->forceFill(['node_id' => null])->save();
    test()->actingAs($user, 'sanctum');

    test()->getJson('/api/v1/app/shipments')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
