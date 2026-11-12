<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Notifications\Domain\Contract\NotificationVariableProvider;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Variables {{tracking_url}} y {{order_reference}} del proveedor de variables del envío: URL ABSOLUTA de la
 * página pública (/t/{token}) y referencia humana de la orden, sin UUIDs fuera del token de la URL.
 * Corre contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('resolves tracking_url and order_reference for the shipment', function (): void {
    $shipmentId = Uuid::generate()->value;
    $token = 'TrkUrlToken_abc-123';

    DB::table('shipments')->insert([
        'id' => $shipmentId,
        'source' => 'ecommerce',
        'external_id' => 'ORD-URL-9#n:'.Uuid::generate()->value,
        'customer_name' => 'Ada Lovelace',
        'address_text' => 'Av. Siempre Viva 123',
        'address_confidence' => 80,
        'address_confidence_level' => 'high',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('tracking_shipments')->insert([
        'id' => Uuid::generate()->value,
        'shipment_id' => $shipmentId,
        'token' => $token,
        'status' => 'ON_THE_WAY',
        'remaining_stops' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $vars = app(NotificationVariableProvider::class)->variablesFor($shipmentId, MessageType::Delivered);

    expect($vars['tracking_url'])->toBe(route('tracking.public.page', ['token' => $token]))
        ->and($vars['tracking_url'])->toStartWith('http')
        ->and($vars['tracking_url'])->toEndWith('/t/'.$token)
        // Multishipping: la referencia humana es la parte antes de '#n:'; nunca un UUID.
        ->and($vars['order_reference'])->toBe('ORD-URL-9');

    // Ningún valor de texto (fuera de la URL) expone un UUID (ni el del envío ni el del nodo de retiro).
    $textValues = implode(' ', array_diff_key($vars, ['tracking_url' => true]));
    expect($vars['order'])->toBe('ORD-URL-9')
        ->and(preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $textValues))->toBe(0);
});

it('omits tracking_url when the shipment has no tracking yet', function (): void {
    $shipmentId = Uuid::generate()->value;

    DB::table('shipments')->insert([
        'id' => $shipmentId,
        'source' => 'ecommerce',
        'external_id' => 'ORD-URL-10',
        'customer_name' => 'Grace Hopper',
        'address_text' => 'Calle 1',
        'address_confidence' => 80,
        'address_confidence_level' => 'high',
        'status' => 'received',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $vars = app(NotificationVariableProvider::class)->variablesFor($shipmentId, MessageType::OrderReceived);

    expect($vars)->not->toHaveKey('tracking_url')
        ->and($vars['order'])->toBe('ORD-URL-10');
});
