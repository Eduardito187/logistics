<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use Logistics\Execution\Domain\ValueObject\LabelVerification;

/**
 * Modelo de lectura del resultado de verificar una etiqueta: forma plana en primitivos para la app.
 *
 * Nunca expone el objeto de dominio; la app decide el color (verde/rojo) a partir de `valid` y `result`.
 */
final readonly class LabelVerificationView
{
    public function __construct(
        public bool $valid,
        public string $result,
    ) {}

    public static function fromResult(LabelVerification $result): self
    {
        return new self($result->isValid(), $result->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'result' => $this->result,
        ];
    }
}
