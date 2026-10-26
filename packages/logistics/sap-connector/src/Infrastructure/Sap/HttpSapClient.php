<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Sap;

use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\PendingRequest;
use Logistics\Outbox\Infrastructure\Resilience\CircuitBreaker;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Throwable;

/**
 * Cliente SAP real detrás del puerto SapClient (ADR 002). Es el ÚNICO lugar que habla HTTP con SAP y
 * solo lo invoca el worker de despacho / la importación por integraciones: nunca un request de usuario.
 *
 * Al empujar un traspaso envía la clave de idempotencia (`event_id`) para que un reintento no duplique
 * el movimiento del lado SAP. Cualquier error de comunicación se traduce a SapConnectorException: el
 * handler lo captura y deja el traspaso Failed para reintentarlo y verlo en reconciliación.
 */
final readonly class HttpSapClient implements SapClient
{
    /** Tope del handshake de conexión (B-2): un connect colgado no debe consumir el timeout total. */
    private const CONNECT_TIMEOUT_SECONDS = 3;

    /** Clave del circuit breaker de este destino (A-5). */
    private const BREAKER_DESTINATION = 'sap';

    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
        private ?string $username,
        private ?string $password,
        private string $ordersPath,
        private string $stockTransfersPath,
        private int $timeout,
    ) {}

    public function pushStockTransfer(StockTransfer $transfer): void
    {
        try {
            // Breaker por destino (A-5): si SAP está caído, corta rápido en vez de gastar el timeout.
            $response = $this->breaker()->call(self::BREAKER_DESTINATION, fn () => $this->request()
                // Header de idempotencia: SAP ignora un reintento con el mismo event_id.
                ->withHeaders(['Idempotency-Key' => $transfer->eventId()])
                ->post($this->url($this->stockTransfersPath), [
                    'event_id' => $transfer->eventId(),
                    'shipment_ref' => $transfer->shipmentRef(),
                    'material' => $transfer->material()->value,
                    'center' => $transfer->center()->value,
                    'warehouse' => $transfer->warehouse()->value,
                    'quantity' => $transfer->quantity(),
                ]));
        } catch (Throwable $exception) {
            throw SapConnectorException::because('fallo de comunicación con SAP al empujar el traspaso: '.$exception->getMessage());
        }

        if (!$response->successful()) {
            throw SapConnectorException::because("SAP respondió {$response->status()} al empujar el traspaso");
        }
    }

    public function fetchOrders(): array
    {
        try {
            // Breaker por destino (A-5): mismo corte rápido para la lectura de pedidos.
            $response = $this->breaker()->call(self::BREAKER_DESTINATION, fn () => $this->request()->get($this->url($this->ordersPath)));
        } catch (Throwable $exception) {
            throw SapConnectorException::because('fallo de comunicación con SAP al leer pedidos: '.$exception->getMessage());
        }

        if (!$response->successful()) {
            throw SapConnectorException::because("SAP respondió {$response->status()} al leer pedidos");
        }

        $body = $response->json();
        $rows = is_array($body) && isset($body['orders']) && is_array($body['orders']) ? $body['orders'] : $body;
        if (!is_array($rows)) {
            return [];
        }

        $orders = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $orders[] = $row;
            }
        }

        return $orders;
    }

    /**
     * Construye el request base con timeout y, si hay credenciales, autenticación básica de servicio.
     */
    private function request(): PendingRequest
    {
        $request = $this->http
            ->timeout($this->timeout)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->acceptJson();

        if (is_string($this->username) && $this->username !== '') {
            $request = $request->withBasicAuth($this->username, $this->password ?? '');
        }

        return $request;
    }

    /**
     * Breaker del destino SAP (A-5). Estado compartido en Valkey; se arma de config por el grupo 'sap'.
     */
    private function breaker(): CircuitBreaker
    {
        return CircuitBreaker::forGroup(self::BREAKER_DESTINATION);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
