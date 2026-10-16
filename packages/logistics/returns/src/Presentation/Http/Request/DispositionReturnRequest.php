<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para disponer una devolución (restock / devolver al seller / descartar).
 */
final class DispositionReturnRequest extends FormRequest
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
            'disposition' => ['required', 'string', 'in:restock,return_to_seller,scrap'],
        ];
    }
}
