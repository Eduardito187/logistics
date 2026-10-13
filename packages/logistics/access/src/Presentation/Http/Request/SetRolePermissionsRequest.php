<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para reemplazar el conjunto completo de permisos de un rol. El id llega por la ruta.
 */
final class SetRolePermissionsRequest extends FormRequest
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
            'permission_keys' => ['present', 'array'],
            'permission_keys.*' => ['string', 'max:128'],
        ];
    }
}
