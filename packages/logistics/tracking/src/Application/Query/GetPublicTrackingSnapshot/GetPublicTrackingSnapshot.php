<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicTrackingSnapshot;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta la foto del enlace público por su token: estado del enlace (activo / vencido / inexistente),
 * vista pública sin datos personales y huella para el `ETag`. Resuelve en UNA consulta lo que antes
 * pedía dos (GetPublicTracking + GetPublicLinkState) y sale del caché de lectura.
 */
final readonly class GetPublicTrackingSnapshot implements Query
{
    public function __construct(
        public string $token,
    ) {}
}
