<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para registrar un permiso nuevo en el catálogo de RBAC.
 *
 * La autorización por puerta la resuelve el middleware; aquí solo se valida la forma del cuerpo. La
 * clave usa el formato "grupo.accion" (el comodín "*" solo lo siembra el sistema, no esta API).
 */
final class RegisterPermissionRequest extends FormRequest
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
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_system' => ['sometimes', 'boolean'],
        ];
    }
}
