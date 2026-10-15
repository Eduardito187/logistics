<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cierre (conciliación) de una recepción en nodo. device_id/user_id se aceptan opcionales
 * porque pueden llegar por header o inyectados desde el token de Auth.
 */
final class ReconcileNodeReceptionRequest extends FormRequest
{
    /**
     * La autorización por puerta (token usuario + dispositivo, roles) la aplica el middleware que monta
     * el integrador; aquí no se decide acceso.
     */
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
            'shipment_id' => ['required', 'uuid'],
            'destination_node_id' => ['required', 'uuid'],
            'occurred_at' => ['required', 'date'],
            'device_id' => ['sometimes', 'string', 'max:128'],
            'user_id' => ['sometimes', 'string', 'max:128'],
        ];
    }
}
