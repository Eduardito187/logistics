<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para definir (o actualizar) un mapeo sku propio → coordenada SAP. Idempotente
 * por sku: redefinir un sku existente actualiza su coordenada. La autorización real (puerta/rol) la
 * aplica el middleware que monta el integrador; aquí solo se valida la forma del payload.
 */
final class DefineMaterialMappingRequest extends FormRequest
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
            'sku' => ['required', 'string', 'max:64'],
            'material' => ['required', 'string', 'max:64'],
            'center' => ['required', 'string', 'max:32'],
            'warehouse' => ['required', 'string', 'max:32'],
        ];
    }
}
