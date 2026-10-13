<?php

declare(strict_types=1);

namespace Logistics\Settings\Application\Command\SetSetting;

use DateTimeImmutable;
use Exception;
use Logistics\Settings\Domain\Contract\SettingsRepository;
use Logistics\Settings\Domain\Exception\SettingsException;
use Logistics\Settings\Domain\SettingVersion;
use Logistics\Settings\Domain\ValueObject\SettingId;
use Logistics\Settings\Domain\ValueObject\SettingKey;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Crea la próxima versión de una clave: pide el número al repositorio y la persiste.
 *
 * @implements CommandHandler<SetSettingCommand>
 */
final readonly class SetSettingHandler implements CommandHandler
{
    public function __construct(
        private SettingsRepository $repository,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof SetSettingCommand) {
            throw SettingsException::because('SetSettingHandler solo maneja SetSettingCommand.');
        }

        $key = SettingKey::fromString($command->key);
        $now = $this->clock->now();
        $effectiveFrom = $this->parseEffectiveFrom($command->effectiveFrom, $now);

        $version = SettingVersion::create(
            SettingId::generate(),
            $key,
            $this->repository->nextVersionFor($key),
            $command->value,
            $effectiveFrom,
            $now,
            $command->createdBy,
        );

        $this->repository->add($version);
    }

    /**
     * Fecha de vigencia: la provista (ISO 8601) o "ahora" si no vino ninguna.
     */
    private function parseEffectiveFrom(?string $raw, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return $now;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception $e) {
            throw SettingsException::because("effectiveFrom no es una fecha válida: {$raw}");
        }
    }
}
