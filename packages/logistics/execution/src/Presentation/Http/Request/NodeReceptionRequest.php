<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

/**
 * Validación de la recepción de un producto en un nodo logístico (paso por nodo).
 *
 * Añade el nodo que recibe el producto.
 */
final class NodeReceptionRequest extends ScanRequest
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
