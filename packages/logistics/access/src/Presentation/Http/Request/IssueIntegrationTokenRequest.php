<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para emitir un token de integración nuevo.
 *
 * `expires_at` es opcional (ISO 8601); si se omite, el token no expira. El secreto en claro se revela
 * una sola vez en la respuesta de esta petición.
 */
final class IssueIntegrationTokenRequest extends FormRequest
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
            'permission_keys' => ['present', 'array'],
            'permission_keys.*' => ['string', 'max:128'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
