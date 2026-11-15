<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Resultado de preguntar si un actor puede OPERAR un pedido o un envío. Cuando no puede, trae el código
 * estable del motivo (`out_of_scope_city` / `out_of_scope_node` / `out_of_scope_assignment`) y su texto.
 */
final readonly class ShipmentScopeDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $code = null,
        public ?string $message = null,
    ) {}

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function denied(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
