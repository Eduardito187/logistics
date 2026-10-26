<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Logistics\Shipments\Infrastructure\Magento\HttpMagentoOrderReader;

/**
 * B-2: el lector de pedidos de Magento fija connectTimeout además del timeout total, para que un connect
 * colgado no consuma el timeout completo. Http::fake captura las opciones de Guzzle del request.
 */
it('fija connectTimeout además del timeout total al leer pedidos de Magento', function (): void {
    $captured = [];
    $http = Http::fake(function (Request $request, array $options) use (&$captured) {
        $captured = $options;

        return Http::response(['items' => []], 200);
    });

    $reader = new HttpMagentoOrderReader($http, 'https://magento.test/', 'service-token', 'processing', 100, 15);
    $reader->fetchPendingOrders();

    expect($captured['connect_timeout'] ?? null)->toBe(3)
        ->and($captured['timeout'] ?? null)->toBe(15);
});
