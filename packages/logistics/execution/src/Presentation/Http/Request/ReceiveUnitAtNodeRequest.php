<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

/**
 * Validación del escaneo de un producto recibido en un nodo. Reúne los campos comunes de un escaneo (GPS,
 * dispositivo/usuario, hora, idempotencia) y añade el nodo destino que recibe.
 *
 * El GPS es OPCIONAL en la recepción (en interiores no hay señal): `lat`/`lng` aceptan null (van juntos) y,
 * como alternativa aditiva, `location: {lat, lng, accuracy_m?, captured_at?}`. Sin GPS el escaneo se
 * registra sin posición; nunca se guarda 0,0 como posición real.
 */
final class ReceiveUnitAtNodeRequest extends ScanRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'destination_node_id' => ['required', 'uuid'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'location' => ['sometimes', 'nullable', 'array'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'location.captured_at' => ['sometimes', 'nullable', 'date'],
        ]);
    }
}
