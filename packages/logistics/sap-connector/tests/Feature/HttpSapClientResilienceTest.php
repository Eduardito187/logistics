<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Infrastructure\Sap\HttpSapClient;

/**
 * Resiliencia del cliente SAP (ADR 002): connectTimeout (B-2) y circuit breaker por destino (A-5). Usa
 * Http::fake capturando las opciones de Guzzle (incluido connect_timeout) y simula un SAP caído con
 * ConnectionException para ejercitar la apertura del circuito.
 */
beforeEach(function (): void {
    Cache::flush();
});

function sapTransfer(): StockTransfer
{
    return StockTransfer::fromReception(
        StockTransferId::generate(),
        'evt-sap-1',
        'DM-1',
        SapMaterial::of('MAT-900'),
        SapCenter::of('C001'),
        SapWarehouse::of('A01'),
        2,
    );
}

it('fija connectTimeout además del timeout total (B-2)', function (): void {
    $captured = [];
    $http = Http::fake(function (Request $request, array $options) use (&$captured) {
        $captured = $options;

        return Http::response(['ok' => true], 200);
    });

    $client = new HttpSapClient($http, 'https://sap.example.test', null, null, '/orders', '/transfers', 10);
    $client->pushStockTransfer(sapTransfer());

    expect($captured['connect_timeout'] ?? null)->toBe(3)
        ->and($captured['timeout'] ?? null)->toBe(10);
});

it('abre el circuito tras varios fallos de conexión y corta rápido sin volver a pegarle a SAP (A-5)', function (): void {
    $attempts = 0;
    $http = Http::fake(function () use (&$attempts): void {
        $attempts++;

        throw new ConnectionException('SAP unreachable');
    });

    $client = new HttpSapClient($http, 'https://sap.example.test', null, null, '/orders', '/transfers', 10);

    // Umbral por defecto: 5 fallos seguidos abren el circuito. Cada intento pega a SAP (y falla).
    for ($i = 0; $i < 5; $i++) {
        expect(fn () => $client->pushStockTransfer(sapTransfer()))->toThrow(SapConnectorException::class);
    }
    expect($attempts)->toBe(5);

    // Circuito ABIERTO: el siguiente intento corta rápido, sin un nuevo request HTTP a SAP.
    expect(fn () => $client->pushStockTransfer(sapTransfer()))->toThrow(SapConnectorException::class);
    expect($attempts)->toBe(5);
});
