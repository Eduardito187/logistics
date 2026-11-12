<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

/**
 * Validación de la prueba de entrega (firma del receptor + foto OBLIGATORIA ya subida).
 *
 * photo_path es REQUERIDO: toda entrega se cierra con foto, sin excepción. photo_url y photo_path salen del
 * endpoint de subida de fotos (contrato aparte): photo_path es la referencia durable en el disco de evidencia
 * (privado) y photo_url una conveniencia de acceso. Se guarda la referencia (photo_path) en la prueba de entrega.
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
            // La foto de entrega es OBLIGATORIA SIEMPRE: toda entrega se cierra con evidencia. Falta → 422
            // (validation_failed), nunca un cierre sin foto. photo_path es la referencia durable del disco de
            // evidencia que devuelve POST /app/deliveries/photos.
            'photo_path' => ['required', 'string', 'max:1024'],
        ]);
    }
}
