<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Tracking\Domain\ValueObject\SurveyReason;

/**
 * Validación del registro de la encuesta de satisfacción (K-06) desde la página pública.
 *
 * Solo valida FORMA y RANGO de los primitivos (rating 1-5, comentario y motivo opcionales); las reglas
 * de negocio (envío entregado, una sola respuesta por envío) viven en el dominio/handler. La única
 * credencial del endpoint es el token de la URL, por eso authorize() devuelve true.
 */
final class RecordSatisfactionSurveyRequest extends FormRequest
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
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', Rule::enum(SurveyReason::class)],
        ];
    }

    public function rating(): int
    {
        return (int) $this->input('rating');
    }

    public function comment(): ?string
    {
        $comment = $this->input('comment');

        return is_string($comment) ? $comment : null;
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) ? $reason : null;
    }
}
