<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Http\Request;

use Commerce\Orders\Application\Command\PlaceOrderFromCart\PlaceOrderFromCart;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo del checkout orquestado (puerta `channels`): toma un carrito existente por
 * `cart_id` y los datos de entrega bajo `ship_to`. Las líneas, el precio (congelado) y la moneda salen
 * del carrito, no del cuerpo: acá no se envían ni se validan. Solo se valida la forma y se arma el
 * comando de Application.
 */
final class CheckoutRequest extends FormRequest
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
            'cart_id' => ['required', 'uuid'],
            'ship_to' => ['required', 'array'],
            'ship_to.customer_name' => ['required', 'string', 'max:255'],
            'ship_to.address_text' => ['required', 'string', 'max:1000'],
            'ship_to.reference' => ['nullable', 'string', 'max:1000'],
            'ship_to.phone' => ['nullable', 'string', 'max:40'],
            'ship_to.email' => ['nullable', 'string', 'email', 'max:180'],
        ];
    }

    /**
     * Arma el comando de Application a partir del payload ya validado.
     */
    public function toCommand(): PlaceOrderFromCart
    {
        /** @var array{cart_id: string, ship_to: array{customer_name: string, address_text: string, reference?: string|null, phone?: string|null, email?: string|null}} $data */
        $data = $this->validated();

        $shipTo = $data['ship_to'];

        return new PlaceOrderFromCart(
            cartId: (string) $data['cart_id'],
            customerName: $shipTo['customer_name'],
            addressText: $shipTo['address_text'],
            addressReference: isset($shipTo['reference']) && $shipTo['reference'] !== ''
                ? (string) $shipTo['reference']
                : null,
            phone: isset($shipTo['phone']) && $shipTo['phone'] !== ''
                ? (string) $shipTo['phone']
                : null,
            email: isset($shipTo['email']) && $shipTo['email'] !== ''
                ? (string) $shipTo['email']
                : null,
        );
    }
}
