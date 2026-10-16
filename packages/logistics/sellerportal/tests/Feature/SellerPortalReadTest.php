<?php

declare(strict_types=1);

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Logistics\SellerPortal\Infrastructure\Identity\PermissionScopedSellerContextResolver;
use Logistics\SellerPortal\Infrastructure\Persistence\DatabaseSellerReadPort;
use Logistics\SellerPortal\Presentation\Http\Controller\GetSellerStockController;
use Logistics\SellerPortal\Presentation\Http\Controller\ListSellerAsnsController;
use Logistics\SellerPortal\Presentation\Http\Controller\ListSellerSettlementsController;
use Logistics\SellerPortal\Presentation\Http\TokenContext;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Filtrado por seller del portal (solo lectura) contra las tablas reales de inbound/inventory/settlement
 * en logistics_test. El dato clave: un seller SOLO ve lo suyo. Skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->sellerA = Uuid::generate()->value;
    $this->sellerB = Uuid::generate()->value;
    $this->warehouse = Uuid::generate()->value;

    // ASNs: 2 del seller A, 1 del seller B.
    DB::table('inbound_asns')->insert([
        sellerPortalAsnRow($this->sellerA, $this->warehouse, 'announced'),
        sellerPortalAsnRow($this->sellerA, $this->warehouse, 'scheduled'),
        sellerPortalAsnRow($this->sellerB, $this->warehouse, 'announced'),
    ]);

    // Lotes: 2 de A, 1 de B. A nivel inventario el dueño es owner_party_id.
    DB::table('inventory_lots')->insert([
        sellerPortalLotRow($this->sellerA, $this->warehouse, 'SKU-A1'),
        sellerPortalLotRow($this->sellerA, $this->warehouse, 'SKU-A2'),
        sellerPortalLotRow($this->sellerB, $this->warehouse, 'SKU-B1'),
    ]);

    // Liquidaciones: 1 de A, 2 de B.
    DB::table('seller_settlements')->insert([
        sellerPortalSettlementRow($this->sellerA),
        sellerPortalSettlementRow($this->sellerB),
        sellerPortalSettlementRow($this->sellerB),
    ]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function sellerPortalAsnRow(string $sellerId, string $warehouseId, string $status): array
{
    return [
        'id' => Uuid::generate()->value,
        'seller_id' => $sellerId,
        'warehouse_id' => $warehouseId,
        'status' => $status,
        'appointment_date' => '2026-10-20',
        'appointment_start' => '08:00',
        'appointment_end' => '10:00',
    ];
}

function sellerPortalLotRow(string $ownerId, string $warehouseId, string $sku): array
{
    return [
        'id' => Uuid::generate()->value,
        'warehouse_id' => $warehouseId,
        'sku' => $sku,
        'fulfillment_mode' => 'fbd',
        'owner_party_id' => $ownerId,
        'seller_sku_ref' => $sku.'-REF',
        'available_quantity' => 10,
        'reserved_quantity' => 2,
        'unit_cost_cents' => 12345,
        'currency' => 'BOB',
        'received_at' => '2026-10-15 12:00:00',
        'goods_receipt_ref' => 'GR-1',
    ];
}

function sellerPortalSettlementRow(string $sellerId): array
{
    return [
        'id' => Uuid::generate()->value,
        'seller_id' => $sellerId,
        'period_from' => '2026-10-01',
        'period_to' => '2026-10-31',
        'gross_cents' => 100000,
        'currency' => 'BOB',
        'commission_basis_points' => 1800,
        'commission_cents' => 18000,
        'fixed_fee_cents' => 2500,
        'storage_fee_cents' => 0,
        'fulfillment_fee_cents' => 0,
        'last_mile_fee_cents' => 0,
        'net_cents' => 79500,
        'status' => 'draft',
        'payment_reference' => null,
    ];
}

/**
 * Crea un Request GET con los permisos del token ya resueltos (lo que dejaría el middleware access.token).
 */
function sellerPortalRequest(string $sellerId, array $query = []): Request
{
    $request = Request::create('/', 'GET', $query);
    $request->attributes->set(TokenContext::PERMISSIONS_ATTRIBUTE, [
        'sellerportal.read',
        PermissionScopedSellerContextResolver::scopeKeyFor($sellerId),
    ]);

    return $request;
}

it('el read port devuelve solo los ASNs del seller consultado', function (): void {
    $port = new DatabaseSellerReadPort;

    $a = $port->asns($this->sellerA);
    $b = $port->asns($this->sellerB);

    expect($a)->toHaveCount(2)->and($b)->toHaveCount(1);
    foreach ($a as $view) {
        expect($view->sellerId)->toBe($this->sellerA);
    }
    expect($b[0]->sellerId)->toBe($this->sellerB);
});

it('el read port devuelve solo los lotes del seller consultado', function (): void {
    $port = new DatabaseSellerReadPort;

    $a = $port->stock($this->sellerA);

    expect($a)->toHaveCount(2);
    foreach ($a as $view) {
        expect($view->ownerPartyId)->toBe($this->sellerA);
    }
    expect($port->stock($this->sellerB))->toHaveCount(1);
});

it('el read port devuelve solo las liquidaciones del seller consultado', function (): void {
    $port = new DatabaseSellerReadPort;

    expect($port->settlements($this->sellerA))->toHaveCount(1)
        ->and($port->settlements($this->sellerB))->toHaveCount(2);
});

it('el controller de ASNs responde 200 solo con los datos del seller del token', function (): void {
    $controller = new ListSellerAsnsController(new PermissionScopedSellerContextResolver, new DatabaseSellerReadPort);

    $response = $controller(sellerPortalRequest($this->sellerA));

    expect($response)->toBeInstanceOf(JsonResponse::class)
        ->and($response->getStatusCode())->toBe(200);

    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['seller_id'])->toBe($this->sellerA)
        ->and($payload['data'])->toHaveCount(2);
    foreach ($payload['data'] as $row) {
        expect($row['seller_id'])->toBe($this->sellerA);
    }
});

it('el controller de stock responde solo con el stock del seller del token', function (): void {
    $controller = new GetSellerStockController(new PermissionScopedSellerContextResolver, new DatabaseSellerReadPort);

    $response = $controller(sellerPortalRequest($this->sellerA));
    $payload = json_decode((string) $response->getContent(), true);

    expect($payload['data'])->toHaveCount(2);
    foreach ($payload['data'] as $row) {
        expect($row['owner_party_id'])->toBe($this->sellerA);
    }
});

it('el controller de liquidaciones responde solo con las del seller del token', function (): void {
    $controller = new ListSellerSettlementsController(new PermissionScopedSellerContextResolver, new DatabaseSellerReadPort);

    $response = $controller(sellerPortalRequest($this->sellerB));
    $payload = json_decode((string) $response->getContent(), true);

    expect($payload['seller_id'])->toBe($this->sellerB)
        ->and($payload['data'])->toHaveCount(2);
});

it('corta con 403 si el seller_id pedido no coincide con el del token (nunca lee datos de otro)', function (): void {
    $controller = new ListSellerAsnsController(new PermissionScopedSellerContextResolver, new DatabaseSellerReadPort);

    // Token de A, pero piden explícitamente los datos de B.
    $request = sellerPortalRequest($this->sellerA, ['seller_id' => $this->sellerB]);

    try {
        $controller($request);
        $this->fail('Se esperaba un corte 403.');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(403);
    }
});
