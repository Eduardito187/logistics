<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

/**
 * Validación de un intento de entrega fallido.
 *
 * El motivo (`reason`) es ahora un code del catálogo CONFIGURABLE: aquí solo se valida su forma (string no
 * vacío); la pertenencia al catálogo ACTIVO y las exigencias (foto/nota) las hace el handler contra el
 * catálogo (422 problem+json si el code está inactivo/no existe o falta la foto/nota requerida). Así los
 * 5 motivos de siempre siguen válidos (están sembrados) y el chofer puede usar cualquier motivo activo.
 */
final class FailedDeliveryRequest extends ScanRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'reason' => ['required', 'string', 'max:64'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);
    }
}
