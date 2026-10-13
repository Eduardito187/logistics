<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la subida de una foto de evidencia (prueba de entrega o daño).
 *
 * La foto se sube detrás de este contrato: el endpoint la persiste en el almacenamiento S3 y devuelve
 * la URL, que luego viaja en el escaneo de prueba de entrega (photo_url).
 */
final class UploadPhotoRequest extends FormRequest
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
            'shipment_id' => ['required', 'uuid'],
            'photo' => ['required', 'file', 'image', 'max:10240'],
        ];
    }
}
