<?php

declare(strict_types=1);

use Logistics\Promise\Infrastructure\Persistence\Eloquent\PromiseRecord;

/*
| Cobertura de los controllers HTTP y FormRequests de la puerta channels del contexto promise, por sus
| rutas reales (prefijo /api/v1/channels). Se omite el middleware de puerta para ejercitar solo el
| contrato del endpoint: validación (422), errores de dominio (404/422) y los happy-path que no dependen
| de cupo sembrado (confirmar/expirar una promesa reservada, y los cálculos que devuelven vacío sin
| cobertura). El envelope JSON uniforme (RespondsWithJson) queda cubierto de paso.
|
| Corre contra logistics_test (RefreshDatabase); se marca skip sin PostgreSQL.
*/

beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const CVP_UUID = '0192f0a0-0000-7000-8000-0000000000ff';

// ---------------------------------------------------------------------------
// QuotePromiseController + QuotePromiseRequest
// ---------------------------------------------------------------------------

it('rechaza cotizar sin cuerpo (422)', function (): void {
    $this->postJson('/api/v1/channels/promises', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['shipment_ref', 'zone_id', 'slot_date', 'time_slot']);
});

it('rechaza cotizar con fecha de franja mal formada (422)', function (): void {
    $this->postJson('/api/v1/channels/promises', [
        'shipment_ref' => 'SHIP-1',
        'zone_id' => 'zone-1',
        'slot_date' => '20-10-2026',
        'time_slot' => 'AM',
    ])->assertStatus(422)->assertJsonValidationErrors(['slot_date']);
});

// ---------------------------------------------------------------------------
// ReserveDeliverySlotController + ReserveDeliverySlotRequest
// ---------------------------------------------------------------------------

it('rechaza reservar franja de despacho con order_id no-uuid (422)', function (): void {
    $this->postJson('/api/v1/channels/delivery-slots', [
        'order_id' => 'no-es-uuid',
        'zone_id' => 'zone-1',
        'date' => '2026-10-20',
        'window_start' => '09:00',
        'window_end' => '13:00',
    ])->assertStatus(422)->assertJsonValidationErrors(['order_id']);
});

// ---------------------------------------------------------------------------
// CalculateDeliveryPromiseController + CalculateDeliveryPromiseRequest
// ---------------------------------------------------------------------------

it('rechaza calcular promesa sin zona (422)', function (): void {
    $this->getJson('/api/v1/channels/promises/offers')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['zone_id']);
});

it('devuelve una colección vacía cuando la zona no tiene cobertura', function (): void {
    $this->getJson('/api/v1/channels/promises/offers?zone_id='.CVP_UUID)
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.count', 0);
});

// ---------------------------------------------------------------------------
// ListAvailableSlotsController + ListAvailableSlotsRequest
// ---------------------------------------------------------------------------

it('rechaza listar franjas disponibles sin fecha (422)', function (): void {
    $this->getJson('/api/v1/channels/promises/available-slots?zone_id='.CVP_UUID)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['date']);
});

// ---------------------------------------------------------------------------
// ReserveMunicipalitySlotController + ReserveMunicipalitySlotRequest
// ---------------------------------------------------------------------------

it('rechaza reservar franja de municipio sin envío (422)', function (): void {
    $this->postJson('/api/v1/channels/shipments/slot-reservation', [
        'date' => '2026-10-20',
        'window_start' => '09:00',
        'window_end' => '13:00',
    ])->assertStatus(422)->assertJsonValidationErrors(['shipment_id']);
});

it('rechaza reservar franja de municipio con latitud sin longitud (422)', function (): void {
    $this->postJson('/api/v1/channels/shipments/slot-reservation', [
        'shipment_id' => CVP_UUID,
        'date' => '2026-10-20',
        'window_start' => '09:00',
        'window_end' => '13:00',
        'latitude' => -16.5,
    ])->assertStatus(422)->assertJsonValidationErrors(['latitude']);
});

// ---------------------------------------------------------------------------
// GetPromiseController
// ---------------------------------------------------------------------------

it('rechaza consultar una promesa con id no-uuid (422)', function (): void {
    $this->getJson('/api/v1/channels/promises/no-es-uuid')
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'invalid_input');
});

it('devuelve 404 al consultar una promesa inexistente', function (): void {
    $this->getJson('/api/v1/channels/promises/'.CVP_UUID)
        ->assertStatus(404)
        ->assertJsonPath('error.type', 'not_found');
});

// ---------------------------------------------------------------------------
// ConfirmPromiseController
// ---------------------------------------------------------------------------

it('rechaza confirmar una promesa con id no-uuid (422)', function (): void {
    $this->postJson('/api/v1/channels/promises/no-es-uuid/confirmation')
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'invalid_input');
});

it('devuelve 404 al confirmar una promesa inexistente', function (): void {
    $this->postJson('/api/v1/channels/promises/'.CVP_UUID.'/confirmation')
        ->assertStatus(404)
        ->assertJsonPath('error.type', 'not_found');
});

it('confirma una promesa reservada y devuelve su estado CONFIRMED', function (): void {
    $promise = PromiseRecord::factory()->reserved()->create(['time_slot' => 'AM']);

    $this->postJson("/api/v1/channels/promises/{$promise->id}/confirmation")
        ->assertOk()
        ->assertJsonPath('data.status', 'CONFIRMED');
});

// ---------------------------------------------------------------------------
// ReservePromiseController + ReservePromiseRequest
// ---------------------------------------------------------------------------

it('rechaza reservar una promesa sin ttl (422)', function (): void {
    $this->postJson('/api/v1/channels/promises/'.CVP_UUID.'/reservation', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ttl_minutes']);
});

it('rechaza reservar una promesa con id no-uuid (422)', function (): void {
    $this->postJson('/api/v1/channels/promises/no-es-uuid/reservation', ['ttl_minutes' => 30])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'invalid_input');
});

// ---------------------------------------------------------------------------
// ExpireReservationController
// ---------------------------------------------------------------------------

it('rechaza expirar una reserva con id no-uuid (422)', function (): void {
    $this->deleteJson('/api/v1/channels/promises/no-es-uuid/reservation')
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'invalid_input');
});

it('devuelve 404 al expirar la reserva de una promesa inexistente', function (): void {
    $this->deleteJson('/api/v1/channels/promises/'.CVP_UUID.'/reservation')
        ->assertStatus(404)
        ->assertJsonPath('error.type', 'not_found');
});

it('expira la reserva de una promesa reservada y responde 204', function (): void {
    $promise = PromiseRecord::factory()->reserved()->create(['time_slot' => 'AM']);

    $this->deleteJson("/api/v1/channels/promises/{$promise->id}/reservation")
        ->assertNoContent();
});
