<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetActivationOptions;

use DateTimeImmutable;
use Exception;
use Logistics\Capacity\Application\ReadModel\ActivationOptionsView;
use Logistics\Capacity\Domain\Contract\CapacityRulesetProvider;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Arma el catálogo de activación de cupos a partir de las reglas vigentes: qué franjas se ofrecen, qué
 * unidades acepta una ventana y cuáles son las ventanas por defecto. Solo lee las reglas (settings
 * versionado o la config del package); los municipios no se enumeran acá, eso es del catálogo de coverage.
 *
 * @implements QueryHandler<GetActivationOptionsQuery, ActivationOptionsView>
 */
final readonly class GetActivationOptionsHandler implements QueryHandler
{
    public function __construct(
        private CapacityRulesetProvider $rules,
        private Clock $clock,
    ) {}

    public function handle(Query $query): ActivationOptionsView
    {
        assert($query instanceof GetActivationOptionsQuery);

        return ActivationOptionsView::fromRuleset(
            $this->rules->currentForMunicipality($query->municipalityId, $this->parseAt($query->at)),
        );
    }

    private function parseAt(?string $raw): DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return $this->clock->now();
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            throw CapacityException::because("at no es una fecha válida: {$raw}");
        }
    }
}
