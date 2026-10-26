<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la apertura de una recepción en nodo: anuncia el envío que llega a un nodo destino, por
 * qué corredor (tipo de nodo origen/destino) y qué productos se esperan.
 */
final class OpenNodeReceptionRequest extends FormRequest
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
            'origin_node_id' => ['required', 'uuid'],
            'destination_node_id' => ['required', 'uuid', 'different:origin_node_id'],
            'origin_node_kind' => ['required', 'string', 'in:CD,STORE'],
            'destination_node_kind' => ['required', 'string', 'in:CD,STORE'],
            'expected_unit_ids' => ['required', 'array', 'min:1'],
            'expected_unit_ids.*' => ['required', 'string', 'max:64'],
            'occurred_at' => ['required', 'date'],
        ];
    }
}
