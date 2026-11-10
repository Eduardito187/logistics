<?php

declare(strict_types=1);

namespace Logistics\Shared\Presentation\Filament\Concerns;

/**
 * Etiqueta de navegación SIN Title Case.
 *
 * Filament, por defecto, pasa la etiqueta plural del recurso por `getTitleCasePluralModelLabel()`
 * (Str::title), que en español capitaliza mal las preposiciones: "Días bloqueados" → "Días Bloqueados",
 * "Cargas de combustible" → "Cargas De Combustible". Este trait devuelve la etiqueta plural YA traducida
 * (de `lang/es.json`) tal cual, sin recapitalizar, respetando el casing correcto del español.
 *
 * No pisa una etiqueta explícita: si el recurso define `$navigationLabel` o su propio
 * `getNavigationLabel()`, ese gana (el método de la clase tiene prioridad sobre el del trait).
 */
trait PlainNavigationLabel
{
    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? static::getPluralModelLabel();
    }
}
