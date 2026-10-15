<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Http\Request;

/**
 * Validación de la reimpresión de etiquetas (puerta app).
 *
 * Reusa las reglas de emisión (origen, destino, líneas) y agrega el motivo obligatorio de la
 * reimpresión, que queda registrado al revocar las etiquetas anteriores (ADR 004).
 */
final class ReprintOrderLabelsRequest extends EmitShipmentLabelsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
