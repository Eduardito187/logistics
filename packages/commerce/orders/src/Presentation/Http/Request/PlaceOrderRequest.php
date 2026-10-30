<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Http\Request;

use Commerce\Orders\Application\Command\PlaceOrder\PlaceOrder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo del checkout (puerta `channels`).
 *
 * El storefront envía la instantánea del carrito (líneas con precio congelado en centavos) más los
 * datos de entrega bajo `ship_to`. La autenticación del cliente la resuelve el middleware de la puerta;
 * aquí solo se valida la forma del cuerpo y se arma el comando de Application.
 */
final class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            'ship_to' => ['required', 'array'],
            'ship_to.customer_name' => ['required', 'string', 'max:255'],
            'ship_to.address_text' => ['required', 'string', 'max:1000'],
            'ship_to.reference' => ['nullable', 'string', 'max:1000'],
            'ship_to.phone' => ['nullable', 'string', 'max:40'],
            'ship_to.email' => ['nullable', 'string', 'email', 'max:180'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:255'],
            'lines.*.product_name' => ['required', 'string', 'max:255'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Arma el comando de Application a partir del payload ya validado.
     */
    public function toCommand(): PlaceOrder
    {
        /** @var array{customer_id?: string|null, currency?: string|null, ship_to: array{customer_name: string, address_text: string, reference?: string|null, phone?: string|null, email?: string|null}, lines: list<array{sku: string, product_name: string, unit_price_cents: int|string, quantity: int|string}>} $data */
        $data = $this->validated();

        $shipTo = $data['ship_to'];

        $lines = [];
        foreach ($data['lines'] as $line) {
            $lines[] = [
                'sku' => (string) $line['sku'],
                'product_name' => (string) $line['product_name'],
                'unit_price_cents' => (int) $line['unit_price_cents'],
                'quantity' => (int) $line['quantity'],
            ];
        }

        return new PlaceOrder(
            customerName: $shipTo['customer_name'],
            addressText: $shipTo['address_text'],
            lines: $lines,
            customerId: isset($data['customer_id']) && $data['customer_id'] !== ''
                ? (string) $data['customer_id']
                : null,
            addressReference: isset($shipTo['reference']) && $shipTo['reference'] !== ''
                ? (string) $shipTo['reference']
                : null,
            phone: isset($shipTo['phone']) && $shipTo['phone'] !== ''
                ? (string) $shipTo['phone']
                : null,
            email: isset($shipTo['email']) && $shipTo['email'] !== ''
                ? (string) $shipTo['email']
                : null,
            currency: isset($data['currency']) && $data['currency'] !== ''
                ? (string) $data['currency']
                : 'BOB',
        );
    }
}
