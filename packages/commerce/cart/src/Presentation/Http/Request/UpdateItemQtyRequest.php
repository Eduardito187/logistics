<?php

declare(strict_types=1);

namespace Commerce\Cart\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo para fijar la cantidad de un SKU del carrito.
 *
 * Cantidad 0 remueve la línea (regla del dominio), por eso se admite min:0.
 */
final class UpdateItemQtyRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:0'],
        ];
    }
}
