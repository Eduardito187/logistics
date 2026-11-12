<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListUsers;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los usuarios. Filtros opcionales y aditivos:
 *   - `status` → estado del usuario (active|suspended|invited).
 *   - `role`   → clave de rol asignada (p. ej. `picker`).
 *   - `search` → texto libre sobre nombre o email (sin distinguir mayúsculas).
 *   - `limit`/`offset` → si `limit` no es null, el handler devuelve un Page<UserView> (página + total)
 *     en lugar de list<UserView>, para que Presentation arme el envelope estándar `data` + `meta`.
 */
final readonly class ListUsers implements Query
{
    public function __construct(
        public ?string $status = null,
        public ?string $role = null,
        public ?string $search = null,
        public ?int $limit = null,
        public int $offset = 0,
    ) {}
}
