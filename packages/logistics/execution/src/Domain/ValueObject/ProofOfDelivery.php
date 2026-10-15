<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Prueba de entrega capturada en el punto del cliente: una confirmación obligatoria (QR del cliente
 * o firma) y, opcionalmente, la foto. El momento exacto queda en capturedAt.
 *
 * La foto se referencia de dos formas complementarias: `photoPath` es la referencia DURABLE en el
 * almacenamiento (relativa al disco de evidencia, privado), y `photoUrl` es una conveniencia de
 * acceso que puede no existir para un disco privado. La referencia durable es `photoPath`.
 */
final readonly class ProofOfDelivery
{
    private function __construct(
        public string $confirmation,
        public ?string $photoUrl,
        public DateTimeImmutable $capturedAt,
        public ?string $photoPath,
    ) {}

    public static function of(
        string $confirmation,
        ?string $photoUrl,
        DateTimeImmutable $capturedAt,
        ?string $photoPath = null,
    ): self {
        $trimmed = trim($confirmation);
        if ($trimmed === '') {
            throw ExecutionException::because('la prueba de entrega requiere una confirmación (QR o firma)');
        }

        return new self($trimmed, self::normalize($photoUrl), $capturedAt, self::normalize($photoPath));
    }

    public function equals(self $other): bool
    {
        return $this->confirmation === $other->confirmation
            && $this->photoUrl === $other->photoUrl
            && $this->photoPath === $other->photoPath
            && $this->capturedAt == $other->capturedAt;
    }

    /**
     * Recorta y trata la cadena vacía como ausencia (null).
     */
    private static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
