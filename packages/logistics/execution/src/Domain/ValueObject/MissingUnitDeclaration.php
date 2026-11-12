<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Declaración de un producto faltante en la conciliación: qué producto y por qué motivo no se recibió.
 */
final readonly class MissingUnitDeclaration
{
    private function __construct(
        public string $unitId,
        public MissingUnitReason $reason,
    ) {}

    public static function of(string $unitId, MissingUnitReason $reason): self
    {
        $unit = trim($unitId);
        if ($unit === '') {
            throw ExecutionException::because('la declaración de un faltante requiere el id del producto');
        }

        return new self($unit, $reason);
    }

    /**
     * @return array{unit_id: string, reason: string}
     */
    public function toArray(): array
    {
        return ['unit_id' => $this->unitId, 'reason' => $this->reason->value];
    }
}
