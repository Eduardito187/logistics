<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para asignar un rol (por clave) a un usuario. El id del usuario llega por la ruta.
 */
final class AssignRoleToUserRequest extends FormRequest
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
            'role_key' => ['required', 'string', 'max:128'],
        ];
    }
}
