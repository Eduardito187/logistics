<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicLinkState;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta si el enlace público de un token está activo, vencido o no existe (ver PublicLinkState).
 */
final readonly class GetPublicLinkState implements Query
{
    public function __construct(
        public string $token,
    ) {}
}
