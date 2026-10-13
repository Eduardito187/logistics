<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Prueba de entrega capturada en el punto del cliente: una confirmación obligatoria (QR del cliente
 * o firma) y, opcionalmente, la URL de una foto. El momento exacto queda en capturedAt.
 */
final readonly class ProofOfDelivery
{
    private function __construct(
        public string $confirmation,
        public ?string $photoUrl,
        public DateTimeImmutable $capturedAt,
    ) {}

    public static function of(string $confirmation, ?string $photoUrl, DateTimeImmutable $capturedAt): self
    {
        $trimmed = trim($confirmation);
        if ($trimmed === '') {
            throw ExecutionException::because('la prueba de entrega requiere una confirmación (QR o firma)');
        }

        $photo = $photoUrl !== null ? trim($photoUrl) : null;
        if ($photo === '') {
            $photo = null;
        }

        return new self($trimmed, $photo, $capturedAt);
    }

    public function equals(self $other): bool
    {
        return $this->confirmation === $other->confirmation
            && $this->photoUrl === $other->photoUrl
            && $this->capturedAt == $other->capturedAt;
    }
}
