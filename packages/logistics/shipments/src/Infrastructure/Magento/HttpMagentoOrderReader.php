<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Magento;

use Illuminate\Http\Client\Factory as HttpClient;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Shipments\Application\Contract\MagentoOrderReader;
use Logistics\Shipments\Domain\ValueObject\OrderSource;

/**
 * Adaptador real del lector de pedidos de Magento sobre su API REST (SHP-07).
 *
 * Trae los pedidos en el estado configurado (por defecto "processing") y los traduce al comando ÚNICO
 * de ingreso. No valida stock ni materiales SAP (ADR 002): solo normaliza el pedido para entregarlo.
 * La idempotencia por (MAGENTO, increment_id) la garantiza el ingreso, no este lector; por eso es
 * seguro reconsultar Magento sin duplicar envíos.
 *
 * Decisiones de infraestructura que viven acá: endpoint, token de servicio, timeout y el mapeo del
 * JSON de Magento a primitivos del comando. El dominio nunca ve Magento.
 */
final class HttpMagentoOrderReader implements MagentoOrderReader
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $accessToken,
        private readonly string $status,
        private readonly int $pageSize,
        private readonly int $timeout,
    ) {}

    public function fetchPendingOrders(): array
    {
        $response = $this->http
            ->withToken($this->accessToken)
            ->timeout($this->timeout)
            // B-2: tope del handshake de conexión, separado del timeout total.
            ->connectTimeout(3)
            ->acceptJson()
            ->get(rtrim($this->baseUrl, '/').'/rest/V1/orders', [
                'searchCriteria[filter_groups][0][filters][0][field]' => 'status',
                'searchCriteria[filter_groups][0][filters][0][value]' => $this->status,
                'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'eq',
                'searchCriteria[pageSize]' => $this->pageSize,
            ]);

        /** @var array{items?: list<array<string, mixed>>} $body */
        $body = $response->json() ?? [];
        $items = $body['items'] ?? [];

        $commands = [];
        foreach ($items as $order) {
            $command = $this->toCommand($order);
            if ($command !== null) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    /**
     * Traduce un pedido de Magento al comando de ingreso; devuelve null si el pedido no trae lo mínimo.
     *
     * @param array<string, mixed> $order
     */
    private function toCommand(array $order): ?ReceiveShipment
    {
        $externalId = isset($order['increment_id']) ? (string) $order['increment_id'] : '';
        if ($externalId === '') {
            return null;
        }

        $address = $this->shippingAddress($order);
        if ($address === null) {
            return null;
        }

        $lines = $this->lines($order);
        if ($lines === []) {
            return null;
        }

        return new ReceiveShipment(
            source: OrderSource::Magento->value,
            externalId: $externalId,
            customerName: $address['name'],
            addressText: $address['text'],
            addressReference: null,
            lat: null,
            lng: null,
            lines: $lines,
        );
    }

    /**
     * @param  array<string, mixed>                   $order
     * @return array{name: string, text: string}|null
     */
    private function shippingAddress(array $order): ?array
    {
        $extension = $order['extension_attributes'] ?? [];
        $shipping = is_array($extension) ? ($extension['shipping_assignments'][0]['shipping']['address'] ?? null) : null;
        if (!is_array($shipping)) {
            return null;
        }

        $street = $shipping['street'] ?? [];
        $streetText = is_array($street) ? implode(', ', array_map(static fn ($part): string => (string) $part, $street)) : (string) $street;
        $city = isset($shipping['city']) ? (string) $shipping['city'] : '';
        $text = trim($streetText.($city !== '' ? ', '.$city : ''));
        if ($text === '') {
            return null;
        }

        $firstName = isset($shipping['firstname']) ? (string) $shipping['firstname'] : '';
        $lastName = isset($shipping['lastname']) ? (string) $shipping['lastname'] : '';
        $name = trim($firstName.' '.$lastName);
        if ($name === '') {
            $name = 'Magento customer';
        }

        return ['name' => $name, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>    $order
     * @return list<ShipmentLineInput>
     */
    private function lines(array $order): array
    {
        $items = $order['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sku = isset($item['sku']) ? (string) $item['sku'] : '';
            $quantity = isset($item['qty_ordered']) ? (int) $item['qty_ordered'] : 0;
            if ($sku === '' || $quantity < 1) {
                continue;
            }
            $name = isset($item['name']) ? (string) $item['name'] : $sku;

            // Precio unitario OPCIONAL (para el detalle de productos en las notificaciones). Magento lo
            // manda en la moneda del pedido; si no viene, la línea queda sin precio.
            $price = isset($item['price']) && is_numeric($item['price']) ? (float) $item['price'] : null;

            // Imagen OPCIONAL: Magento la expone por extension_attributes si la integración la habilita.
            $extension = $item['extension_attributes'] ?? [];
            $imageUrl = is_array($extension) && isset($extension['image_url']) && is_string($extension['image_url'])
                ? $extension['image_url']
                : null;

            $lines[] = new ShipmentLineInput($sku, $name, $quantity, $imageUrl, $price);
        }

        return $lines;
    }
}
