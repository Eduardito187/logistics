<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

/**
 * Validación del escaneo de un producto recibido en un nodo. Reúne los campos comunes de un escaneo (GPS,
 * dispositivo/usuario, hora, idempotencia) y añade el nodo destino que recibe.
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
        ]);
    }
}
