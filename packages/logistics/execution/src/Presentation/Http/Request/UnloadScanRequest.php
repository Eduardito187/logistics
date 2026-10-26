<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

/**
 * Validación de la descarga guiada de un producto en una parada (qué baja en cada punto).
 *
 * Añade la parada (nodo) donde se descarga el producto.
 */
final class UnloadScanRequest extends ScanRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'node_id' => ['required', 'uuid'],
        ]);
    }
}
