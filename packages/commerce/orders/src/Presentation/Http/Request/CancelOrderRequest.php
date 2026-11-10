<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo de la cancelación de una orden (puertas `channels` / `integrations`).
 *
 * El id de la orden viaja en la ruta; el cuerpo solo lleva un motivo opcional. La autenticación de la
 * puerta la resuelve el middleware; aquí solo se valida la forma del cuerpo.
 */
final class CancelOrderRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Motivo normalizado: null cuando viene vacío o ausente.
     */
    public function reason(): ?string
    {
        /** @var array{reason?: string|null} $data */
        $data = $this->validated();

        return isset($data['reason']) && $data['reason'] !== ''
            ? (string) $data['reason']
            : null;
    }
}
