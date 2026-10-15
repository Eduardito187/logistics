<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Sap;

use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\PendingRequest;
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
            $response = $this->request()
                // Header de idempotencia: SAP ignora un reintento con el mismo event_id.
                ->withHeaders(['Idempotency-Key' => $transfer->eventId()])
                ->post($this->url($this->stockTransfersPath), [
                    'event_id' => $transfer->eventId(),
                    'shipment_ref' => $transfer->shipmentRef(),
                    'material' => $transfer->material()->value,
                    'center' => $transfer->center()->value,
                    'warehouse' => $transfer->warehouse()->value,
                    'quantity' => $transfer->quantity(),
                ]);
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
            $response = $this->request()->get($this->url($this->ordersPath));
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
        $request = $this->http->timeout($this->timeout)->acceptJson();

        if (is_string($this->username) && $this->username !== '') {
            $request = $request->withBasicAuth($this->username, $this->password ?? '');
        }

        return $request;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
