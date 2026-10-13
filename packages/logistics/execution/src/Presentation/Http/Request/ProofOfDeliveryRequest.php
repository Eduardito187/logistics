<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

/**
 * Validación de la prueba de entrega (QR del cliente o firma, y opcionalmente una foto ya subida).
 *
 * El photo_url, si viene, es la URL devuelta por el endpoint de subida de fotos (contrato aparte).
 */
final class ProofOfDeliveryRequest extends ScanRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'confirmation' => ['required', 'string', 'max:512'],
            'photo_url' => ['nullable', 'string', 'url', 'max:2048'],
        ]);
    }
}
