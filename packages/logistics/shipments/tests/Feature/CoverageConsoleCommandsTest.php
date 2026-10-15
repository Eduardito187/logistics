<?php

declare(strict_types=1);

use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Shipments\Application\Contract\MagentoOrderReader;

/**
 * Cobertura de los comandos de consola del contexto shipments (Patrón H del brief W2).
 *
 * El pull de Magento se ejercita con un lector falso inyectado en el contenedor (no HTTP real) que
 * devuelve comandos de ingreso; así se recorre el despacho por el CommandBus (transacción + outbox) fila
 * por fila. La ingesta manual se ejercita con argumentos y opciones reales. Ejercita el happy-path y los
 * caminos de "sin datos"/malformado. Contra PostgreSQL (base logistics_test) porque el ingreso persiste.
 */
beforeEach(function (): void {
    // Lo dejamos explícito: estos comandos ingresan pedidos, así que tocan la base de test.
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Lector de Magento falso que devuelve los comandos de ingreso dados, sin HTTP.
 *
 * @param list<ReceiveShipment> $orders
 */
function fakeMagentoOrderReader(array $orders): MagentoOrderReader
{
    return new class($orders) implements MagentoOrderReader
    {
        /** @param list<ReceiveShipment> $orders */
        public function __construct(private readonly array $orders) {}

        public function fetchPendingOrders(): array
        {
            return $this->orders;
        }
    };
}

it('pull de Magento sin pedidos pendientes termina con éxito', function (): void {
    app()->instance(MagentoOrderReader::class, fakeMagentoOrderReader([]));

    $this->artisan('logistics:shipments:pull-magento')
        ->expectsOutputToContain('No pending Magento orders to receive.')
        ->assertSuccessful();
});

it('pull de Magento ingresa los pedidos que trae el lector', function (): void {
    $order = new ReceiveShipment(
        source: 'MAGENTO',
        externalId: 'MAG-'.uniqid(),
        customerName: 'Cliente Magento',
        addressText: 'Av. Busch 123, La Paz',
        addressReference: null,
        lat: null,
        lng: null,
        lines: [new ShipmentLineInput('SKU-MAG-A', 'Televisor', 1)],
    );

    app()->instance(MagentoOrderReader::class, fakeMagentoOrderReader([$order]));

    $this->artisan('logistics:shipments:pull-magento')
        ->assertSuccessful();
});

it('ingesta manual recibe un pedido con una línea válida', function (): void {
    $this->artisan('logistics:shipments:receive-manual', [
        'external-id' => 'MAN-'.uniqid(),
        'customer' => 'Juan Operador',
        'address' => 'Calle Comercio 100, La Paz',
        '--reference' => 'Puerta roja',
        '--lat' => '-16.5',
        '--lng' => '-68.15',
        '--line' => ['SKU-MAN-1:Horno:2', 'malformada-sin-formato'],
    ])->assertSuccessful();
});

it('ingesta manual falla cuando no se pasa ninguna línea', function (): void {
    $this->artisan('logistics:shipments:receive-manual', [
        'external-id' => 'MAN-'.uniqid(),
        'customer' => 'Juan Operador',
        'address' => 'Calle Comercio 100, La Paz',
    ])->assertFailed();
});
