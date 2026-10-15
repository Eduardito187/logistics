<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetCapacityRuleset;

use DateTimeImmutable;
use Exception;
use Logistics\Capacity\Domain\Contract\CapacityRulesetProvider;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Devuelve las reglas de cupo vigentes a la fecha pedida en su forma primitiva (array), listas para el
 * admin o la API. No expone el objeto de dominio (regla 7).
 *
 * @implements QueryHandler<GetCapacityRulesetQuery, array<string, mixed>>
 */
final readonly class GetCapacityRulesetHandler implements QueryHandler
{
    public function __construct(
        private CapacityRulesetProvider $rules,
        private Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof GetCapacityRulesetQuery);

        return $this->rules
            ->currentForMunicipality($query->municipalityId, $this->parseAt($query->at))
            ->toArray();
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
