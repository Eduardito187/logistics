<?php

declare(strict_types=1);

namespace Commerce\Cart\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo para abrir un carrito del storefront.
 *
 * La sesión identifica al carrito de invitado; el cliente es opcional (null = invitado). La moneda es
 * un código ISO de 3 letras; si no viaja, el comando aplica su valor por defecto (BOB).
 */
final class OpenCartRequest extends FormRequest
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
            'session_id' => ['required', 'string', 'max:255'],
            'customer_id' => ['sometimes', 'nullable', 'uuid'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
