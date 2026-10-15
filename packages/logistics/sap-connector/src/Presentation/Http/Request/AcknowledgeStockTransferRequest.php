<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del callback de SAP que confirma un traspaso ya empujado. Se identifica por `event_id` (la
 * clave de idempotencia que SAP recibió al empujarse el traspaso). La autorización real (puerta
 * integrations / credenciales de servicio) la aplica el middleware que monta el integrador.
 */
final class AcknowledgeStockTransferRequest extends FormRequest
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
        ];
    }
}
