<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Execution\Application\Command\ReconcileNodeReception\ReconcileNodeReception;

/**
 * Validación del cierre (conciliación) de una recepción en nodo. device_id/user_id se aceptan opcionales
 * porque pueden llegar por header o inyectados desde el token de Auth.
 *
 * Informe opcional del operador (aditivo, el cuerpo histórico sigue siendo válido): `notes`, `photo_path`
 * (referencia que devuelve POST /app/deliveries/photos), `missing: [{unit_id, reason}]` con motivo
 * `not_arrived | damaged | wrong_store | other`, y `surplus: [{unit_id, belongs_to_shipment_id?}]`.
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
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'missing' => ['sometimes', 'nullable', 'array', 'max:1000'],
            'missing.*.unit_id' => ['required', 'string', 'max:64'],
            'missing.*.reason' => ['required', 'string', Rule::in(ReconcileNodeReception::missingReasons())],
            'surplus' => ['sometimes', 'nullable', 'array', 'max:1000'],
            'surplus.*.unit_id' => ['required', 'string', 'max:64'],
            'surplus.*.belongs_to_shipment_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
