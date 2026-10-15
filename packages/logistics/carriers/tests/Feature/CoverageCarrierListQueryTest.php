<?php

declare(strict_types=1);

use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierFactory;

/**
 * Cobertura de la lectura de transportistas (Patrón F + C): el endpoint GET /api/v1/app/carriers
 * atraviesa ListCarriersController → QueryBus → ListCarriersHandler → repositorio → CarrierMapper
 * (toDomain) → CarrierView (fromDomain/toArray). Cubre la colección completa, el filtro por estado y
 * la rama de paginación opcional (E-02).
 *
 * Se omite el middleware de puerta; corre contra logistics_test (RefreshDatabase); skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('lista los transportistas como proyecciones de lectura con su forma primitiva', function (): void {
    CarrierFactory::new()->rated(4.5, 10)->withDocuments([
        ['type' => 'poliza', 'reference' => 'POL-1'],
    ])->create(['name' => 'Andina Logística']);

    $this->getJson('/api/v1/app/carriers')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                ['id', 'name', 'tax_id', 'status', 'average_rating', 'rating_count', 'documents'],
            ],
        ])
        ->assertJsonPath('data.0.name', 'Andina Logística')
        ->assertJsonPath('data.0.rating_count', 10);
});

it('filtra el listado por estado suspendido', function (): void {
    CarrierFactory::new()->create(['name' => 'Activa SRL']);
    CarrierFactory::new()->suspended()->create(['name' => 'Suspendida SRL']);

    $response = $this->getJson('/api/v1/app/carriers?status=suspended')->assertOk();

    $nombres = array_column($response->json('data'), 'name');
    expect($nombres)->toContain('Suspendida SRL')
        ->and($nombres)->not->toContain('Activa SRL');
});

it('devuelve la colección paginada cuando el cliente la pide', function (): void {
    CarrierFactory::new()->count(3)->create();

    $this->getJson('/api/v1/app/carriers?page=1&per_page=2')
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'meta',
        ]);
});
