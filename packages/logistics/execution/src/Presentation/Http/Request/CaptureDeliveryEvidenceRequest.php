<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;

/**
 * Validación de la captura de una evidencia de entrega ligada al cliente (puerta app, multipart).
 *
 * `kind` ∈ pod|damage|signature|id_document. La identidad del cliente (id, documento, nombre, teléfono)
 * viaja como snapshot — la app la toma del pedido/envío. El GPS y `captured_at` sellan el hecho en terreno.
 */
final class CaptureDeliveryEvidenceRequest extends FormRequest
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
        $kinds = array_map(static fn (EvidenceKind $k): string => $k->value, EvidenceKind::cases());

        return [
            'shipment_id' => ['required', 'uuid'],
            'kind' => ['required', 'string', 'in:'.implode(',', $kinds)],
            // Límite de 8 MB: suficiente para una foto de evidencia de un teléfono y acotado para no admitir
            // cargas excesivas (defensa ante content-length abusivo).
            'photo' => ['required', 'file', 'image', 'max:8192'],
            'customer_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'document_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'captured_at' => ['sometimes', 'nullable', 'date'],
            'device_id' => ['sometimes', 'string', 'max:128'],
            'user_id' => ['sometimes', 'string', 'max:128'],
            'idempotency_key' => ['sometimes', 'string', 'max:128'],
        ];
    }
}
