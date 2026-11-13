<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Compensation\Infrastructure\Read\DbCustomerContactReader;
use Logistics\Compensation\Infrastructure\Read\DbPromisedWindowReader;

/*
| Lectores de ventana prometida y de contacto del cliente contra la proyección real de shipments.
*/

beforeEach(function (): void {
    $this->windowReader = new DbPromisedWindowReader(DB::connection());
    $this->contactReader = new DbCustomerContactReader(DB::connection());
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Inserta un envío con fecha/ventana/email y devuelve su id.
 */
function dpwrShipment(?string $date, ?string $windowEnd, ?string $email): string
{
    $id = (string) Str::uuid();
    DB::table('shipments')->insert([
        'id' => $id,
        'source' => 'test',
        'external_id' => 'EXT-'.bin2hex(random_bytes(4)),
        'customer_name' => 'Cliente de prueba',
        'customer_email' => $email,
        'address_text' => 'Calle Falsa 456',
        'address_confidence' => 80,
        'address_confidence_level' => 'medium',
        'status' => 'delivered',
        'delivery_date' => $date,
        'delivery_window_end' => $windowEnd,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('devuelve el fin de la ventana prometida con fecha y hora', function (): void {
    $shipment = dpwrShipment('2026-11-10', '18:00', null);

    $end = $this->windowReader->promisedEndFor($shipment);

    expect($end)->not->toBeNull()
        ->and($end->format('Y-m-d H:i'))->toBe('2026-11-10 18:00');
});

it('cae a 23:59 si no hay fin de ventana cargado', function (): void {
    $shipment = dpwrShipment('2026-11-10', null, null);

    $end = $this->windowReader->promisedEndFor($shipment);

    expect($end)->not->toBeNull()
        ->and($end->format('H:i'))->toBe('23:59');
});

it('devuelve null si el envío no tiene fecha de entrega', function (): void {
    $shipment = dpwrShipment(null, null, null);

    expect($this->windowReader->promisedEndFor($shipment))->toBeNull();
});

it('nunca marca reprogramación por el cliente (señal conservadora)', function (): void {
    $shipment = dpwrShipment('2026-11-10', '18:00', null);

    expect($this->windowReader->wasRescheduledByCustomer($shipment))->toBeFalse();
});

it('resuelve el email del cliente del envío', function (): void {
    $shipment = dpwrShipment('2026-11-10', '18:00', 'cliente@dismac.test');

    expect($this->contactReader->emailForShipment($shipment))->toBe('cliente@dismac.test');
});

it('devuelve null si el envío no tiene email', function (): void {
    $shipment = dpwrShipment('2026-11-10', '18:00', null);

    expect($this->contactReader->emailForShipment($shipment))->toBeNull();
});
