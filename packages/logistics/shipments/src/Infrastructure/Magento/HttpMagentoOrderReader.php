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
 *
 * RECOJO EN TIENDA: el nodo destino viaja como `destinationNodeCode` (el ingreso lo traduce a su id por
 * `network_nodes.code`). Se resuelve en cascada:
 *  1. el código de punto de retiro del pedido, leído de los atributos de extensión configurados (por
 *     defecto `pickup_location_code`, el de Magento MSI In-Store Pickup), en el pedido o en el envío;
 *  2. si no viene código, el método de envío (`shipping_method` / `shipping.method`) cuando el mapa lo
 *     reconoce (módulos de retiro que codifican la tienda en el método, p. ej. `storepickup_SCZ01`).
 * El código hallado pasa por el mapa `magento.pickup_location_map` (código Magento => código de nodo); sin
 * entrada en el mapa se usa tal cual (se asume que Magento usa el mismo código que la red). Sin nada de
 * eso el pedido es a domicilio (nodo null), como siempre.
 */
final class HttpMagentoOrderReader implements MagentoOrderReader
{
    /**
     * @param array<string, string> $pickupLocationMap    código de retiro o método de envío de Magento => código de nodo
     * @param list<string>          $pickupCodeAttributes atributos de extensión donde Magento manda el código de retiro
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $accessToken,
        private readonly string $status,
        private readonly int $pageSize,
        private readonly int $timeout,
        private readonly array $pickupLocationMap = [],
        private readonly array $pickupCodeAttributes = ['pickup_location_code'],
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
            destinationNodeCode: $this->pickupNodeCode($order),
        );
    }

    /**
     * Código del nodo de RETIRO EN TIENDA del pedido, o null si es a domicilio (ver la cascada en la clase).
     *
     * @param array<string, mixed> $order
     */
    private function pickupNodeCode(array $order): ?string
    {
        $code = $this->pickupLocationCode($order);
        if ($code !== null) {
            return $this->pickupLocationMap[$code] ?? $code;
        }

        foreach ($this->shippingMethods($order) as $method) {
            if (isset($this->pickupLocationMap[$method])) {
                return $this->pickupLocationMap[$method];
            }
        }

        return null;
    }

    /**
     * Código de punto de retiro que manda Magento en los atributos de extensión configurados, buscado en el
     * pedido y en el envío (`shipping_assignments[0].shipping`). Null si no viene ninguno.
     *
     * @param array<string, mixed> $order
     */
    private function pickupLocationCode(array $order): ?string
    {
        $extension = $order['extension_attributes'] ?? [];
        $shipping = is_array($extension) ? ($extension['shipping_assignments'][0]['shipping'] ?? null) : null;
        $shippingExtension = is_array($shipping) ? ($shipping['extension_attributes'] ?? null) : null;

        foreach ([$extension, $shippingExtension] as $source) {
            if (!is_array($source)) {
                continue;
            }
            foreach ($this->pickupCodeAttributes as $attribute) {
                $value = $source[$attribute] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return null;
    }

    /**
     * Métodos de envío del pedido (raíz `shipping_method` y `shipping_assignments[0].shipping.method`).
     *
     * @param  array<string, mixed> $order
     * @return list<string>
     */
    private function shippingMethods(array $order): array
    {
        $extension = $order['extension_attributes'] ?? [];
        $candidates = [
            $order['shipping_method'] ?? null,
            is_array($extension) ? ($extension['shipping_assignments'][0]['shipping']['method'] ?? null) : null,
        ];

        $methods = [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $methods[] = trim($candidate);
            }
        }

        return $methods;
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
