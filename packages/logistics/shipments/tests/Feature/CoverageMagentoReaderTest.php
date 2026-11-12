<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Contract\MagentoOrderReader;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Infrastructure\Magento\HttpMagentoOrderReader;

/**
 * Cobertura del adaptador HTTP real del lector de pedidos de Magento (Patrón G del brief W2).
 *
 * Nunca pega HTTP real: Http::fake devuelve el JSON que el adaptador espera de /rest/V1/orders y se
 * asevera el mapeo del pedido de Magento al comando ÚNICO de ingreso (ReceiveShipment), así como el
 * descarte de pedidos incompletos (sin increment_id, sin dirección o sin líneas válidas). Ejercita
 * código real; no caza bugs. No toca la base de datos, así que no depende de PostgreSQL.
 */
function fakeMagentoReader(): HttpMagentoOrderReader
{
    return new HttpMagentoOrderReader(
        app(Factory::class),
        'https://magento.test/',
        'service-token',
        'processing',
        100,
        10,
    );
}

/**
 * Pedido de Magento mínimo bien formado: id, dirección de envío y una línea con SKU y cantidad.
 *
 * @param  array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function magentoOrder(array $overrides = []): array
{
    return array_replace_recursive([
        'increment_id' => '100000123',
        'extension_attributes' => [
            'shipping_assignments' => [
                [
                    'shipping' => [
                        'address' => [
                            'firstname' => 'Ana',
                            'lastname' => 'Pérez',
                            'street' => ['Av. Arce 2345', 'Piso 3'],
                            'city' => 'La Paz',
                        ],
                    ],
                ],
            ],
        ],
        'items' => [
            [
                'sku' => 'SKU-MAG-1',
                'name' => 'Heladera',
                'qty_ordered' => 2,
                'price' => 4999.90,
                'extension_attributes' => ['image_url' => 'https://magento.test/img/heladera.png'],
            ],
        ],
    ], $overrides);
}

it('mapea los pedidos de Magento al comando único de ingreso', function (): void {
    Http::fake([
        '*' => Http::response(['items' => [magentoOrder()]], 200),
    ]);

    $commands = fakeMagentoReader()->fetchPendingOrders();

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toBeInstanceOf(ReceiveShipment::class)
        ->and($commands[0]->source)->toBe(OrderSource::Magento->value)
        ->and($commands[0]->externalId)->toBe('100000123')
        ->and($commands[0]->customerName)->toBe('Ana Pérez')
        ->and($commands[0]->addressText)->toBe('Av. Arce 2345, Piso 3, La Paz')
        ->and($commands[0]->lines)->toHaveCount(1)
        ->and($commands[0]->lines[0]->sku)->toBe('SKU-MAG-1')
        ->and($commands[0]->lines[0]->quantity)->toBe(2);
});

it('usa un nombre por defecto cuando el pedido no trae nombre de cliente', function (): void {
    $order = magentoOrder();
    unset($order['extension_attributes']['shipping_assignments'][0]['shipping']['address']['firstname']);
    unset($order['extension_attributes']['shipping_assignments'][0]['shipping']['address']['lastname']);

    Http::fake(['*' => Http::response(['items' => [$order]], 200)]);

    $commands = fakeMagentoReader()->fetchPendingOrders();

    expect($commands)->toHaveCount(1)
        ->and($commands[0]->customerName)->toBe('Magento customer');
});

it('descarta pedidos sin increment_id, sin dirección o sin líneas válidas', function (): void {
    $sinId = magentoOrder();
    unset($sinId['increment_id']);

    $sinDireccion = magentoOrder(['increment_id' => '100000124']);
    unset($sinDireccion['extension_attributes']['shipping_assignments']);

    $sinLineas = magentoOrder(['increment_id' => '100000125']);
    $sinLineas['items'] = [['sku' => '', 'qty_ordered' => 0]];

    Http::fake([
        '*' => Http::response(['items' => [$sinId, $sinDireccion, $sinLineas]], 200),
    ]);

    expect(fakeMagentoReader()->fetchPendingOrders())->toBe([]);
});

it('devuelve lista vacía cuando la respuesta no trae items', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    expect(fakeMagentoReader()->fetchPendingOrders())->toBe([]);
});

it('un pedido a domicilio no trae nodo destino', function (): void {
    Http::fake(['*' => Http::response(['items' => [magentoOrder()]], 200)]);

    expect(fakeMagentoReader()->fetchPendingOrders()[0]->destinationNodeCode)->toBeNull();
});

it('mapea el punto de retiro de Magento MSI (pickup_location_code) al código de nodo destino', function (): void {
    $order = magentoOrder(['extension_attributes' => ['pickup_location_code' => ' T-SCZ-01 ']]);

    Http::fake(['*' => Http::response(['items' => [$order]], 200)]);

    expect(fakeMagentoReader()->fetchPendingOrders()[0]->destinationNodeCode)->toBe('T-SCZ-01');
});

it('traduce el código de retiro con el mapa configurado y también lo lee del envío', function (): void {
    $order = magentoOrder();
    $order['extension_attributes']['shipping_assignments'][0]['shipping']['extension_attributes'] = ['store_code' => 'SCZ01'];

    Http::fake(['*' => Http::response(['items' => [$order]], 200)]);

    $reader = new HttpMagentoOrderReader(app(Factory::class), 'https://magento.test/', 'service-token', 'processing', 100, 10, ['SCZ01' => 'T-SCZ-01'], ['pickup_location_code', 'store_code']);

    expect($reader->fetchPendingOrders()[0]->destinationNodeCode)->toBe('T-SCZ-01');
});

it('reconoce el retiro por método de envío solo si está en el mapa', function (): void {
    $pickup = magentoOrder();
    $pickup['extension_attributes']['shipping_assignments'][0]['shipping']['method'] = 'storepickup_SCZ01';
    $home = magentoOrder(['increment_id' => '100000126', 'shipping_method' => 'flatrate_flatrate']);

    Http::fake(['*' => Http::response(['items' => [$pickup, $home]], 200)]);

    $reader = new HttpMagentoOrderReader(app(Factory::class), 'https://magento.test/', 'service-token', 'processing', 100, 10, ['storepickup_SCZ01' => 'T-SCZ-01']);
    $commands = $reader->fetchPendingOrders();

    expect($commands[0]->destinationNodeCode)->toBe('T-SCZ-01')
        ->and($commands[1]->destinationNodeCode)->toBeNull();
});

it('el contenedor arma el lector con el mapa de retiro de la config', function (): void {
    config([
        'logistics-shipments.magento.base_url' => 'https://magento.test',
        'logistics-shipments.magento.access_token' => 'service-token',
        'logistics-shipments.magento.pickup_location_map' => ['SCZ01' => 'T-SCZ-01'],
    ]);
    app()->forgetInstance(MagentoOrderReader::class);

    Http::fake(['*' => Http::response(['items' => [magentoOrder(['extension_attributes' => ['pickup_location_code' => 'SCZ01']])]], 200)]);

    expect(app(MagentoOrderReader::class)->fetchPendingOrders()[0]->destinationNodeCode)->toBe('T-SCZ-01');
});
