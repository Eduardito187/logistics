<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para encolar un traspaso de stock hacia SAP desde una recepción en nodo.
 *
 * El `event_id` es el identificador de la recepción que origina el traspaso: respalda la idempotencia
 * del handler (la misma recepción encola un solo traspaso). La autorización real (puerta/rol) la aplica
 * el middleware que monta el integrador; aquí solo se valida la forma del payload.
 */
final class EnqueueStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:64'],
            'shipment_ref' => ['required', 'string', 'max:64'],
            'sku' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
