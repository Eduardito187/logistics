<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Configuration;

use InvalidArgumentException;

/**
 * Catálogo de secciones de la pantalla «Configuración» (singleton).
 *
 * Cada contexto registra las suyas desde su provider (`register(MiSeccion::class)`); la página solo
 * consulta este registro, así agregar configuración de un contexto nuevo no toca la página ni settings.
 */
final class ConfigurationRegistry
{
    /** @var array<string, class-string<ConfigurationSection>> */
    private array $sections = [];

    /**
     * @param class-string<ConfigurationSection> $section
     */
    public function register(string $section): void
    {
        if (!is_subclass_of($section, ConfigurationSection::class)) {
            throw new InvalidArgumentException("{$section} must extend ".ConfigurationSection::class);
        }

        $this->sections[$section::slug()] = $section;
    }

    /**
     * Secciones visibles para el usuario actual, ordenadas por pestaña y luego por ítem.
     *
     * @return list<class-string<ConfigurationSection>>
     */
    public function accessible(): array
    {
        $visible = array_values(array_filter(
            $this->sections,
            static fn (string $section): bool => $section::canAccess(),
        ));

        usort($visible, static fn (string $a, string $b): int => [$a::tabSort(), $a::tab(), $a::sort(), $a::label()]
            <=> [$b::tabSort(), $b::tab(), $b::sort(), $b::label()]);

        return $visible;
    }

    /**
     * Sección accesible por slug; si no existe o no es accesible, la primera accesible (o null si ninguna).
     *
     * @return class-string<ConfigurationSection>|null
     */
    public function resolve(?string $slug): ?string
    {
        $visible = $this->accessible();

        foreach ($visible as $section) {
            if ($section::slug() === $slug) {
                return $section;
            }
        }

        return $visible[0] ?? null;
    }

    /**
     * Menú lateral: pestañas → ítems, solo con lo accesible.
     *
     * @return list<array{tab: string, items: list<array{slug: string, label: string}>}>
     */
    public function navigation(): array
    {
        $tabs = [];
        foreach ($this->accessible() as $section) {
            $tabs[$section::tab()][] = ['slug' => $section::slug(), 'label' => $section::label()];
        }

        $nav = [];
        foreach ($tabs as $tab => $items) {
            $nav[] = ['tab' => $tab, 'items' => $items];
        }

        return $nav;
    }
}
