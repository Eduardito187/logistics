<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Shipments\Domain\ValueObject\OrderSource;

/**
 * Validación del ingreso único de pedidos por la puerta de integraciones.
 *
 * Normaliza el payload externo (SAP, Magento, etc.) a los primitivos del comando de Application.
 * La idempotencia por (source, external_id) la garantiza el handler, no esta validación.
 */
final class ReceiveShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización por credenciales de servicio la aplica el middleware de la puerta
        // de integraciones (lo cablea el integrador); aquí solo se valida la forma del payload.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sources = array_map(static fn (OrderSource $source): string => $source->value, OrderSource::cases());

        return [
            'source' => ['required', 'string', Rule::in($sources)],
            'external_id' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_document_id' => ['nullable', 'string', 'max:40'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'customer_email' => ['nullable', 'string', 'email', 'max:180'],
            'address_text' => ['required', 'string', 'max:1000'],
            'address_reference' => ['nullable', 'string', 'max:1000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:255'],
            'lines.*.product_name' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.image_url' => ['nullable', 'string', 'url', 'max:2048'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    /**
     * Arma el comando de Application a partir del payload ya validado.
     */
    public function toCommand(): ReceiveShipment
    {
        /** @var array{source: string, external_id: string, customer_name: string, customer_document_id?: string|null, customer_phone?: string|null, customer_email?: string|null, address_text: string, address_reference?: string|null, lat?: float|int|string|null, lng?: float|int|string|null, lines: list<array{sku: string, product_name: string, quantity: int|string, image_url?: string|null, price?: float|int|string|null, currency?: string|null}>} $data */
        $data = $this->validated();

        $lines = [];
        foreach ($data['lines'] as $line) {
            $imageUrl = isset($line['image_url']) && $line['image_url'] !== '' ? (string) $line['image_url'] : null;
            $price = isset($line['price']) && $line['price'] !== '' ? (float) $line['price'] : null;
            $currency = isset($line['currency']) && $line['currency'] !== '' ? (string) $line['currency'] : null;

            $lines[] = new ShipmentLineInput(
                $line['sku'],
                $line['product_name'],
                (int) $line['quantity'],
                $imageUrl,
                $price,
                $currency,
            );
        }

        return new ReceiveShipment(
            source: $data['source'],
            externalId: $data['external_id'],
            customerName: $data['customer_name'],
            addressText: $data['address_text'],
            addressReference: isset($data['address_reference']) && $data['address_reference'] !== ''
                ? (string) $data['address_reference']
                : null,
            lat: isset($data['lat']) && $data['lat'] !== '' ? (float) $data['lat'] : null,
            lng: isset($data['lng']) && $data['lng'] !== '' ? (float) $data['lng'] : null,
            lines: $lines,
            customerDocumentId: isset($data['customer_document_id']) && $data['customer_document_id'] !== ''
                ? (string) $data['customer_document_id']
                : null,
            customerPhone: isset($data['customer_phone']) && $data['customer_phone'] !== ''
                ? (string) $data['customer_phone']
                : null,
            customerEmail: isset($data['customer_email']) && $data['customer_email'] !== ''
                ? (string) $data['customer_email']
                : null,
        );
    }
}
