<?php

declare(strict_types=1);

namespace Logistics\Settings\Application\Query\GetSetting;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el historial completo de una clave: todas sus versiones, de la más nueva a la más vieja.
 */
final readonly class GetSettingHistoryQuery implements Query
{
    public function __construct(
        public string $key,
    ) {}
}
