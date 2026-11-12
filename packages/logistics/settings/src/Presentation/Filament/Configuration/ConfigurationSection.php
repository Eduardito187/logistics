<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Configuration;

use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Logistics\Settings\Application\Command\SetSetting\SetSettingCommand;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Una sección de la pantalla «Configuración» estilo Magento (Stores › Configuration): un ítem del menú
 * lateral, agrupado bajo una pestaña (tab), con sus fieldsets a la derecha y un único «Save Config».
 *
 * Cada bounded context declara SUS secciones en su capa Presentation y las registra en el
 * {@see ConfigurationRegistry} desde su provider: así settings no conoce las claves de otros contextos y
 * cada contexto sigue siendo extraíble. Toda escritura pasa por el command SetSetting (historial versionado
 * y auditable), nunca por Eloquent.
 *
 * La configuración es GLOBAL (no hay alcance por tienda/website como en Magento): cada campo muestra el
 * alcance `[global]` bajo la etiqueta, igual que Magento muestra `[store view]`.
 */
abstract class ConfigurationSection
{
    /** Identificador estable en la URL (`?section=...`). */
    abstract public static function slug(): string;

    /** Pestaña (grupo del menú lateral) en inglés sin traducir; la vista la pasa por __(). */
    abstract public static function tab(): string;

    /** Orden de la pestaña en el menú lateral (menor primero). */
    abstract public static function tabSort(): int;

    /** Nombre del ítem en el menú lateral y título del panel derecho, en inglés sin traducir. */
    abstract public static function label(): string;

    /** Orden del ítem dentro de su pestaña. */
    public static function sort(): int
    {
        return 100;
    }

    /** Quién puede ver y guardar la sección (oculta del menú y 403 si no). */
    abstract public static function canAccess(): bool;

    /**
     * Fieldsets (Section de Filament) con sus campos.
     *
     * @return list<Component>
     */
    abstract public function components(): array;

    /**
     * Valores vigentes para precargar el formulario (forma primitiva).
     *
     * @return array<string, mixed>
     */
    abstract public function state(): array;

    /**
     * Persiste el estado validado del formulario.
     *
     * @param array<string, mixed> $data
     */
    abstract public function save(array $data): void;

    /**
     * Etiqueta con la marca de alcance bajo el nombre, como Magento (`Button Title` / `[store view]`).
     */
    public static function scoped(string $label): HtmlString
    {
        return new HtmlString(
            e(__($label)).'<span class="cfg-scope">['.e(__('global')).']</span>',
        );
    }

    /**
     * Publica una versión nueva de la clave por el bus CQRS (vigente desde ahora).
     *
     * @param array<string, mixed> $value
     */
    protected function publish(string $key, array $value): void
    {
        app(CommandBus::class)->dispatch(new SetSettingCommand(
            key: $key,
            value: $value,
            effectiveFrom: null,
            createdBy: self::actor(),
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function str(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function int(array $data, string $field, int $default): int
    {
        $value = $data[$field] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected static function bool(array $data, string $field): bool
    {
        return (bool) ($data[$field] ?? false);
    }

    private static function actor(): ?string
    {
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        $email = $user instanceof Model ? $user->getAttribute('email') : null;

        return is_string($email) && $email !== '' ? $email : (string) $user->getAuthIdentifier();
    }
}
