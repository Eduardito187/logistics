<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para otorgar o revocar un único permiso de un rol. El id del rol llega por la ruta.
 *
 * La comparten grant y revoke porque ambos solo requieren la clave del permiso afectado.
 */
final class RolePermissionRequest extends FormRequest
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
            'permission_key' => ['required', 'string', 'max:128'],
        ];
    }
}
