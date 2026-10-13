<?php

declare(strict_types=1);

namespace Logistics\Settings\Database\Seeders;

use Illuminate\Container\Container;
use Illuminate\Database\Seeder;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Settings\Infrastructure\Persistence\Eloquent\SettingVersionRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Seeder propio del contexto Configuración: publica las reglas base por defecto de la plataforma.
 *
 * No inserta filas directamente: despacha el comando SetSetting al bus CQRS, igual que haría el admin
 * o la API, para que el dominio asigne id (UUID v7), número de versión y fecha de vigencia y se
 * respete la inmutabilidad del historial. Es idempotente por clave: si una clave ya tiene versiones,
 * no vuelve a publicarla (evita inflar el historial al re-ejecutar el seed).
 *
 * El integrador lo invoca desde el DatabaseSeeder raíz; aquí no se toca ese archivo compartido.
 */
final class SettingsSeeder extends Seeder
{
    /**
     * Reglas base por defecto (clave namespaced => valor primitivo). Cada contexto consumidor las
     * interpreta en su propio dominio; aquí solo se versionan y se sirven.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DEFAULTS = [
        'coverage.default_service_days' => ['days' => 3],
        'cutoff.scz_evening' => ['time' => '14:00', 'timezone' => 'America/La_Paz'],
        'capacity.default_bucket' => ['unit' => 'packages', 'total' => 120],
        'carriers.base_tariff' => ['currency' => 'BOB', 'per_km' => 2.5, 'minimum' => 20.0],
        'notifications.retry_policy' => ['max_attempts' => 5, 'backoff_seconds' => 60],
        'tracking.public_link_ttl_hours' => ['hours' => 72],
    ];

    public function run(): void
    {
        $bus = Container::getInstance()->make(CommandBus::class);

        foreach (self::DEFAULTS as $key => $value) {
            if ($this->alreadyPublished($key)) {
                continue;
            }

            $bus->dispatch(new SetSettingCommand(
                key: $key,
                value: $value,
                effectiveFrom: null,
                createdBy: 'seeder',
            ));
        }
    }

    /**
     * ¿La clave ya tiene al menos una versión publicada?
     */
    private function alreadyPublished(string $key): bool
    {
        return SettingVersionRecord::query()->where('key', $key)->exists();
    }
}
