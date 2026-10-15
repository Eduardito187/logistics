<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la verificación de una etiqueta QR escaneada en terreno (P1-EXE-12).
 *
 * El payload canónico es exactamente lo que se firmó (contrato con la app Flutter); la firma viene en
 * base64url y key_id es el campo "k" del QR.
 */
final class VerifyLabelRequest extends FormRequest
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
            'payload' => ['required', 'string', 'max:4096'],
            'signature' => ['required', 'string', 'max:512'],
            'key_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
