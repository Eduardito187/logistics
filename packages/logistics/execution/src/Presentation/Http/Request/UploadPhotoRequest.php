<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la subida de una foto de evidencia (prueba de entrega o daño).
 *
 * La foto se sube detrás de este contrato: el endpoint la persiste en el disco de evidencia que
 * corresponde al `kind` (`pod` para prueba de entrega, `damage` para daños) y devuelve su referencia
 * (disco + path), que luego viaja en el escaneo de prueba de entrega (photo_path). Sin `kind`, se
 * asume prueba de entrega.
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
            'kind' => ['sometimes', 'string', 'in:pod,damage'],
        ];
    }
}
