<?php

declare(strict_types=1);

namespace Logistics\Settings\Application\Query\GetSetting;

use Logistics\Settings\Application\ReadModel\SettingView;
use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Domain\Exception\SettingsException;
use Logistics\Settings\Domain\ValueObject\SettingKey;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el historial de una clave como lista de arreglos primitivos (vista de lectura), orden desc.
 *
 * @implements QueryHandler<GetSettingHistoryQuery, list<array{id: string, key: string, version: int, value: array<string, mixed>, effective_from: string, created_at: string, created_by: string|null}>>
 */
final readonly class GetSettingHistoryHandler implements QueryHandler
{
    public function __construct(
        private SettingsRepository $repository,
    ) {}

    /**
     * @return list<array{id: string, key: string, version: int, value: array<string, mixed>, effective_from: string, created_at: string, created_by: string|null}>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof GetSettingHistoryQuery) {
            throw SettingsException::because('GetSettingHistoryHandler solo maneja GetSettingHistoryQuery.');
        }

        $key = SettingKey::fromString($query->key);

        return array_map(
            static fn ($version): array => SettingView::fromDomain($version)->toArray(),
            $this->repository->historyFor($key),
        );
    }
}
