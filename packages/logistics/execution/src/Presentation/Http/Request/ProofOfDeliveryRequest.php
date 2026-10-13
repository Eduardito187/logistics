<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

/**
 * Validación de la prueba de entrega (QR del cliente o firma, y opcionalmente una foto ya subida).
 *
 * El photo_url y el photo_path, si vienen, salen del endpoint de subida de fotos (contrato aparte):
 * photo_path es la referencia durable en el disco de evidencia (privado) y photo_url una conveniencia
 * de acceso. Se guarda la referencia (photo_path) en la prueba de entrega.
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
            'photo_path' => ['nullable', 'string', 'max:1024'],
        ]);
    }
}
