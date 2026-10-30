<?php

declare(strict_types=1);

namespace Commerce\Cart\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo para agregar una línea al carrito.
 *
 * El precio unitario (en centavos) viaja YA resuelto por pricing/storefront: el carrito no consulta
 * precios. La cantidad por defecto es 1 si no viaja.
 */
final class AddItemRequest extends FormRequest
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
            'sku' => ['required', 'string', 'max:255'],
            'product_name' => ['required', 'string', 'max:255'],
            'unit_price_cents' => ['required', 'integer', 'min:0'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
