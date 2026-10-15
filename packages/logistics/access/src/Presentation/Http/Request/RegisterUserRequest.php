<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para registrar un usuario nuevo.
 *
 * Los roles llegan como lista de claves (slugs); el estado inicial por defecto es "active".
 */
final class RegisterUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role_keys' => ['sometimes', 'array'],
            'role_keys.*' => ['required_with:role_keys', 'string', 'max:128'],
            'status' => ['sometimes', 'string', 'in:active,suspended,invited'],
        ];
    }
}
