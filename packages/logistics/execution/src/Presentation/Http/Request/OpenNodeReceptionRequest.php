<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la apertura de una recepción en nodo.
 *
 * Forma corta (preferida): solo `{shipment_id, occurred_at}`; el servidor deriva destino, origen, corredor
 * y productos esperados. Forma completa (histórica, sigue aceptada): además origen/destino, sus tipos y
 * los productos esperados. Cualquier campo de la forma completa que se mande se respeta; los que falten se
 * derivan.
 */
final class OpenNodeReceptionRequest extends FormRequest
{
    /**
     * La autorización por puerta (token usuario + dispositivo, roles) la aplica el middleware que monta
     * el integrador; el alcance por tienda lo aplica el controller (el destino puede derivarse).
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
            'origin_node_id' => ['sometimes', 'nullable', 'uuid'],
            'destination_node_id' => ['sometimes', 'nullable', 'uuid', 'different:origin_node_id'],
            'origin_node_kind' => ['sometimes', 'nullable', 'string', 'in:CD,STORE'],
            'destination_node_kind' => ['sometimes', 'nullable', 'string', 'in:CD,STORE'],
            'expected_unit_ids' => ['sometimes', 'nullable', 'array', 'min:1'],
            'expected_unit_ids.*' => ['required', 'string', 'max:64'],
            'occurred_at' => ['required', 'date'],
        ];
    }
}
