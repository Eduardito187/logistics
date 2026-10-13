<?php

declare(strict_types=1);

namespace Logistics\Settings\Application\Query\GetSetting;

use DateTimeImmutable;
use Exception;
use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Domain\Exception\SettingsException;
use Logistics\Settings\Domain\ValueObject\SettingKey;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Devuelve el `value` (arreglo primitivo) vigente de una clave a la fecha pedida, o null si no hay.
 *
 * @implements QueryHandler<GetSettingQuery, array<string, mixed>|null>
 */
final readonly class GetSettingHandler implements QueryHandler
{
    public function __construct(
        private SettingsRepository $repository,
        private Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(Query $query): ?array
    {
        if (!$query instanceof GetSettingQuery) {
            throw SettingsException::because('GetSettingHandler solo maneja GetSettingQuery.');
        }

        $key = SettingKey::fromString($query->key);
        $at = $this->parseAt($query->at);

        $version = $this->repository->currentFor($key, $at);

        return $version?->value;
    }

    private function parseAt(?string $raw): DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return $this->clock->now();
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception $e) {
            throw SettingsException::because("at no es una fecha válida: {$raw}");
        }
    }
}
