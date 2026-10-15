<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Query;

use Logistics\Coverage\Application\Contract\ZoneRuleSettings;
use Logistics\Coverage\Application\ReadModel\ZoneRuleView;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve la regla de cobertura vigente de una zona desde la configuración versionada (contexto
 * settings), vía el puerto ZoneRuleSettings. Devuelve null si la zona no tiene ninguna versión
 * publicada o si no se pudo leer.
 *
 * @implements QueryHandler<GetEffectiveZoneRule, ZoneRuleView|null>
 */
final readonly class GetEffectiveZoneRuleHandler implements QueryHandler
{
    public function __construct(private ZoneRuleSettings $settings) {}

    public function handle(Query $query): ?ZoneRuleView
    {
        assert($query instanceof GetEffectiveZoneRule);

        $rules = $this->settings->effectiveFor($query->zoneId, $query->at);

        return $rules === null ? null : ZoneRuleView::fromRules($query->zoneId, $rules);
    }
}
