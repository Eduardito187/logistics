<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para definir un rol nuevo con su conjunto inicial de permisos.
 *
 * Los permisos llegan como lista de claves (slugs "grupo.accion"); el handler valida que existan.
 */
final class DefineRoleRequest extends FormRequest
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
            'key' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permission_keys' => ['sometimes', 'array'],
            'permission_keys.*' => ['required_with:permission_keys', 'string', 'max:128'],
            'is_system' => ['sometimes', 'boolean'],
        ];
    }
}
