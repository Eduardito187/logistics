<?php

declare(strict_types=1);

namespace Logistics\Settings\Application\Query\GetSetting;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el valor vigente de una clave a una fecha dada.
 *
 * `at` es una fecha ISO 8601 opcional; si viene null, se consulta a "ahora" (lo resuelve el handler
 * con el Clock).
 */
final readonly class GetSettingQuery implements Query
{
    public function __construct(
        public string $key,
        public ?string $at = null,
    ) {}
}
