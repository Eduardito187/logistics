<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Configuration\Integrations;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\Crypt;
use Logistics\Settings\Infrastructure\Integration\IntegrationConfig;
use Logistics\Settings\Presentation\Filament\Configuration\ConfigurationSection;
use Logistics\Shared\Presentation\Filament\Concerns\ResolvesUserRoles;

/**
 * Base de las secciones de la pestaña «Integrations» (ruteo, SAP, almacenamiento, FCM).
 *
 * Solo administrador (credenciales). Los secretos se guardan CIFRADOS (Crypt::encryptString) y nunca se
 * precargan en claro: el campo queda vacío con el placeholder «already set», y en blanco conserva el valor.
 */
abstract class IntegrationSection extends ConfigurationSection
{
    use ResolvesUserRoles;

    /** Etiqueta repetida en todas las integraciones. */
    protected const string LABEL_TIMEOUT = 'Timeout (seconds)';

    public static function tab(): string
    {
        return 'Integrations';
    }

    public static function tabSort(): int
    {
        return 90;
    }

    public static function canAccess(): bool
    {
        return static::authUserHasAnyRoleValue('admin');
    }

    protected static function config(): IntegrationConfig
    {
        return app(IntegrationConfig::class);
    }

    protected static function enabledToggle(string $help): Toggle
    {
        return Toggle::make('enabled')
            ->label(static::scoped('Enabled'))
            ->helperText(__($help));
    }

    protected static function timeoutInput(): TextInput
    {
        return TextInput::make('timeout')
            ->label(static::scoped(self::LABEL_TIMEOUT))
            ->numeric()
            ->integer()
            ->minValue(1)
            ->maxValue(120);
    }

    /**
     * Campo de secreto: tipo password, sin precarga, con placeholder que dice si ya hay uno guardado.
     */
    protected static function secretInput(string $integrationKey, string $field, string $label, string $help): TextInput
    {
        return TextInput::make($field)
            ->label(static::scoped($label))
            ->password()
            ->revealable()
            ->placeholder(static::config()->hasSecret($integrationKey, $field) ? __('•••• already set') : __('Not set'))
            ->helperText(__($help));
    }

    /**
     * Nuevo secreto → se cifra; en blanco → se conserva el guardado (ya cifrado); sin ninguno → no se setea.
     *
     * @param  array<string, mixed> $value
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function withSecret(array $value, string $integrationKey, array $data, string $field): array
    {
        $input = static::str($data, $field);
        if ($input !== '') {
            $value[$field] = Crypt::encryptString($input);

            return $value;
        }

        $previous = static::config()->config($integrationKey)[$field] ?? null;
        if (is_string($previous) && $previous !== '') {
            $value[$field] = $previous;
        }

        return $value;
    }
}
