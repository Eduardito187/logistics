<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateServiceClient;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Logistics\Labels\Tests\Support\LabelTestEnv;

// Ejercita los controladores HTTP del contexto etiquetas (Presentation) contra las rutas reales de la
// puerta app e integraciones: listar, emitir, reimprimir y ZPL de un pedido, el bundle de claves
// públicas y la pre-emisión idempotente. Se autentica con Sanctum y se desactiva solo el middleware de
// credenciales de cada puerta (igual que los tests de contrato), para ejercitar el controlador en sí.

beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    LabelTestEnv::configureSigner();
    $this->withoutMiddleware(EnsureDeviceToken::class);
    Sanctum::actingAs(User::factory()->create());
});

/**
 * @return array<string, mixed>
 */
function labelOrderBody(): array
{
    return [
        'origin' => 'CD-SCZ',
        'destination' => 'C',
        'lines' => [
            ['sku' => 'LG-GT32WPP', 'name' => 'Fridge', 'quantity' => 2, 'pieces_per_unit' => 1],
        ],
    ];
}

it('lista vacío las etiquetas de un pedido sin emitir', function (): void {
    $this->getJson('/api/v1/app/orders/DM-260070001/labels')
        ->assertOk()
        ->assertJson(['data' => []]);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('emite las etiquetas de un pedido y las devuelve firmadas', function (): void {
    $this->postJson('/api/v1/app/orders/DM-260070002/labels', labelOrderBody())
        ->assertCreated()
        ->assertJsonStructure(['data' => [['order_number', 'label_id', 'key_id', 'sku', 'qr_content', 'zpl']]]);
    // La rama de conflicto duro (DuplicateLabelException → 409) la cubre EmittedLabelRepositoryTest a
    // nivel de repositorio: `store()` por HTTP genera ids nuevos por emisión, así que no colisiona aquí.
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('devuelve el ZPL concatenado de las etiquetas vigentes', function (): void {
    $this->postJson('/api/v1/app/orders/DM-260070003/labels', labelOrderBody())->assertCreated();

    $response = $this->get('/api/v1/app/orders/DM-260070003/labels/zpl');
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/x-zpl');
    expect($response->getContent())->toStartWith('^XA');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('responde 404 al pedir el ZPL de un pedido sin etiquetas vigentes', function (): void {
    $this->get('/api/v1/app/orders/DM-260070004/labels/zpl')->assertNotFound();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('reimprime las etiquetas de un pedido (revoca y re-emite)', function (): void {
    $this->postJson('/api/v1/app/orders/DM-260070005/labels', labelOrderBody())->assertCreated();

    $this->postJson('/api/v1/app/orders/DM-260070005/labels/reprint', [
        ...labelOrderBody(),
        'reason' => 'Damaged label',
    ])->assertCreated();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('entrega el bundle de claves públicas para verificación offline', function (): void {
    $this->getJson('/api/v1/app/labels/public-keys')
        ->assertOk()
        ->assertJsonPath('data.active_key_id', 1)
        ->assertJsonStructure(['data' => ['active_key_id', 'keys']]);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('pre-emite las etiquetas del pedido por la puerta de integraciones (idempotente)', function (): void {
    $this->withoutMiddleware(AuthenticateServiceClient::class);

    $this->postJson('/api/v1/integrations/orders/DM-260070006/labels/pre-emit', labelOrderBody())
        ->assertOk()
        ->assertJsonStructure(['data']);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
