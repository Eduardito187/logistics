<?php

declare(strict_types=1);

/**
 * Cobertura de los controllers HTTP del contexto capacity por sus rutas reales de la API v1 (puertas app
 * e integrations). Patrón F del brief: se omite el middleware de puerta para ejercitar solo el contrato de
 * los controllers, sus FormRequest (clave natural del bucket) y la cadena CQRS detrás (comandos, consultas,
 * ReadModels y repositorios). Recorre el happy-path y algún 404/422. Corre contra logistics_test
 * (RefreshDatabase); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
    $this->nodeId = '0192cccc-dddd-7eee-8fff-000000000001';
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('define, consume y libera capacidad por la puerta de integraciones', function (): void {
    // Alta del cupo (202 Accepted): clave natural de nodo + total.
    $this->postJson('/api/v1/integrations/capacity/buckets', [
        'node_id' => $this->nodeId,
        'date' => '2026-03-02',
        'time_slot' => '08:00-12:00',
        'unit' => 'deliveries',
        'total' => 40,
    ])->assertStatus(202)
        ->assertJsonPath('data.node_id', $this->nodeId)
        ->assertJsonPath('data.unit', 'deliveries');

    // Consume una parte del cupo recién definido.
    $this->postJson('/api/v1/integrations/capacity/consume', [
        'node_id' => $this->nodeId,
        'date' => '2026-03-02',
        'time_slot' => '08:00-12:00',
        'unit' => 'deliveries',
        'amount' => 5,
    ])->assertStatus(202)
        ->assertJsonPath('data.amount', 5);

    // Libera parte de lo consumido.
    $this->postJson('/api/v1/integrations/capacity/release', [
        'node_id' => $this->nodeId,
        'date' => '2026-03-02',
        'time_slot' => '08:00-12:00',
        'unit' => 'deliveries',
        'amount' => 2,
    ])->assertStatus(202)
        ->assertJsonPath('data.amount', 2);
});

it('rechaza definir un cupo sin clave natural con 422', function (): void {
    // Ni municipio, ni nodo, ni zona: la validación de identidad del bucket falla.
    $this->postJson('/api/v1/integrations/capacity/buckets', [
        'date' => '2026-03-02',
        'time_slot' => '08:00-12:00',
        'unit' => 'deliveries',
        'total' => 40,
    ])->assertStatus(422);
});

it('devuelve el disponible de un cupo existente y 404 cuando no existe', function (): void {
    $this->postJson('/api/v1/integrations/capacity/buckets', [
        'node_id' => $this->nodeId,
        'date' => '2026-03-02',
        'time_slot' => '08:00-12:00',
        'unit' => 'deliveries',
        'total' => 30,
    ])->assertStatus(202);

    $this->getJson('/api/v1/app/capacity/available?'.http_build_query([
        'node_id' => $this->nodeId,
        'date' => '2026-03-02',
        'time_slot' => '08:00-12:00',
        'unit' => 'deliveries',
    ]))->assertOk()
        ->assertJsonStructure(['data']);

    // Clave que no existe: problem+json 404.
    $this->getJson('/api/v1/app/capacity/available?'.http_build_query([
        'node_id' => '0192cccc-dddd-7eee-8fff-0000000000ff',
        'date' => '2026-03-02',
        'time_slot' => '16:00-20:00',
        'unit' => 'deliveries',
    ]))->assertStatus(404);
});

it('devuelve la capacidad efectiva aunque no haya cupo (source none)', function (): void {
    $this->getJson('/api/v1/app/capacity/effective?'.http_build_query([
        'node_id' => $this->nodeId,
        'date' => '2026-03-02',
        'time_slot' => '12:00-16:00',
        'unit' => 'deliveries',
    ]))->assertOk()
        ->assertJsonStructure(['data']);
});

it('rechaza el disponible con una fecha mal formada con 422', function (): void {
    $this->getJson('/api/v1/app/capacity/available?'.http_build_query([
        'node_id' => $this->nodeId,
        'date' => 'no-es-fecha',
        'time_slot' => '08:00-12:00',
        'unit' => 'deliveries',
    ]))->assertStatus(422);
});

it('lista el calendario de cupos de una fecha', function (): void {
    $this->postJson('/api/v1/integrations/capacity/buckets', [
        'node_id' => $this->nodeId,
        'date' => '2026-03-02',
        'time_slot' => '08:00-12:00',
        'unit' => 'deliveries',
        'total' => 20,
    ])->assertStatus(202);

    $this->getJson('/api/v1/app/capacity/calendar?date=2026-03-02')
        ->assertOk()
        ->assertJsonPath('meta.date', '2026-03-02')
        ->assertJsonStructure(['data', 'meta' => ['date', 'count']]);
});

it('rechaza el calendario sin fecha con 422', function (): void {
    $this->getJson('/api/v1/app/capacity/calendar')->assertStatus(422);
});
