<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Service;

use Logistics\Capacity\Domain\ValueObject\SlotDate;

/**
 * Resultado de procesar un lote (chunk) de la activación por rango: cuántos cupos se crearon, cuántos se
 * saltaron (ya existían / día bloqueado / día inactivo), y desde qué fecha debe continuar el siguiente
 * lote (`nextFrom` null = terminado). Es inmutable y primitivo salvo el cursor de continuación.
 */
final readonly class ActivationChunkResult
{
    public function __construct(
        public int $created,
        public int $skippedExisting,
        public int $skippedBlocked,
        public int $skippedInactive,
        public ?SlotDate $nextFrom,
    ) {}

    public function isDone(): bool
    {
        return $this->nextFrom === null;
    }
}
