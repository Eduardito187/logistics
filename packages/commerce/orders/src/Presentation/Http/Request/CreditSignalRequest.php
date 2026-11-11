<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo de las señales de crédito (firma de contrato / pago de inicial). El id de la orden
 * viaja en la ruta; el cuerpo solo lleva una referencia externa opcional (del POS/sistema de crédito).
 */
final class CreditSignalRequest extends FormRequest
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
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** Referencia normalizada: null cuando viene vacía o ausente. */
    public function reference(): ?string
    {
        /** @var array{reference?: string|null} $data */
        $data = $this->validated();

        return isset($data['reference']) && $data['reference'] !== ''
            ? (string) $data['reference']
            : null;
    }
}
