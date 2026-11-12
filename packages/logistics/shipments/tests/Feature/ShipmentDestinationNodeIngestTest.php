<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;

/**
 * Regla del nodo destino en el ingreso (retiro en tienda / traspaso): id explícito > sufijo "#n:{uuid}" del
 * id externo > código de tienda (network_nodes.code). Sin nada, es a domicilio (null). Así la tienda ve sus
 * envíos en «Recepción de mercadería» y «Recojo en tienda». Contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    // Sin preparación propia: la guarda real es el ->skip() encadenado (requiere PostgreSQL).
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function dniStore(string $code): string
{
    $id = Uuid::generate()->value;
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => $code,
        'name' => 'Tienda '.$code,
        'type' => 'store',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function dniReceive(string $source, string $externalId, ?string $nodeId = null, ?string $nodeCode = null): ?string
{
    app(CommandBus::class)->dispatch(new ReceiveShipment(
        source: $source,
        externalId: $externalId,
        customerName: 'Cliente Retiro',
        addressText: 'Retiro en tienda',
        lines: [new ShipmentLineInput('SKU-DNI-1', 'Licuadora', 1)],
        destinationNodeId: $nodeId,
        destinationNodeCode: $nodeCode,
    ));

    $value = DB::table('shipments')->where('source', $source)->where('external_id', $externalId)->value('destination_node_id');

    return is_string($value) ? $value : null;
}

it('toma el nodo del sufijo "#n:{uuid}" de un envío hermano de retiro', function (): void {
    $node = dniStore('T-DNI-SUF');

    expect(dniReceive('commerce', 'ORD-DNI-1#n:'.$node))->toBe($node);
});

it('traduce el código de tienda a su nodo (sin importar mayúsculas)', function (): void {
    $node = dniStore('T-DNI-COD');

    expect(dniReceive('STORE', 'POS-DNI-2', nodeCode: 't-dni-cod'))->toBe($node);
});

it('el id explícito manda sobre el sufijo y el código', function (): void {
    $explicit = dniStore('T-DNI-EXP');
    $other = dniStore('T-DNI-OTR');

    expect(dniReceive('commerce', 'ORD-DNI-3#n:'.$other, $explicit, 'T-DNI-OTR'))->toBe($explicit);
});

it('un pedido a domicilio (o con un código desconocido) queda sin nodo destino', function (): void {
    expect(dniReceive('STORE', 'POS-DNI-4'))->toBeNull()
        ->and(dniReceive('STORE', 'POS-DNI-5', nodeCode: 'NO-EXISTE'))->toBeNull();
});
