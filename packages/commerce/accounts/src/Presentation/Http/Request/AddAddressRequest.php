<?php

declare(strict_types=1);

namespace Commerce\Accounts\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo para agregar una dirección a una cuenta (puerta `channels`).
 *
 * Los campos replican el value object Address del dominio (etiqueta única dentro de la cuenta). El id
 * de la cuenta viaja en la ruta ({account}), no en el cuerpo. Presentation no arma el comando aquí
 * porque este necesita el id de la ruta: el controller lo compone con estos valores.
 */
final class AddAddressRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:120'],
            'recipient' => ['required', 'string', 'max:255'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
