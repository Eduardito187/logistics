{{--
    Configuración estilo Magento (Stores › Configuration): barra de alcance + «Save Config», menú lateral de
    pestañas plegables y el panel de la sección con fieldsets plegables y etiquetas a la izquierda.
    Estilos propios con prefijo cfg- dentro de la vista (no dependen de recompilar el tema: funciona en
    cualquier docker nuevo). Modo oscuro por la clase .dark de Filament.
--}}
<x-filament-panels::page>
    <style>
        .cfg { --cfg-line: rgb(229 231 235); --cfg-soft: rgb(243 244 246); --cfg-bar: rgb(249 250 251); --cfg-text: rgb(17 24 39); --cfg-muted: rgb(107 114 128); --cfg-accent: var(--primary-600, #E30613); }
        .dark .cfg { --cfg-line: rgb(255 255 255 / .1); --cfg-soft: rgb(255 255 255 / .04); --cfg-bar: rgb(255 255 255 / .03); --cfg-text: rgb(243 244 246); --cfg-muted: rgb(156 163 175); }

        .cfg-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; padding: .9rem 1rem; background: var(--cfg-bar); border-top: 1px solid var(--cfg-line); border-bottom: 1px solid var(--cfg-line); }
        .cfg-scope-switch { display: flex; align-items: center; gap: .75rem; font-size: .875rem; color: var(--cfg-text); }
        .cfg-scope-switch strong { font-weight: 700; }
        .cfg-help { display: inline-flex; align-items: center; justify-content: center; width: 1.35rem; height: 1.35rem; border-radius: 9999px; background: var(--cfg-muted); color: #fff; font-size: .75rem; font-weight: 700; cursor: help; }

        .cfg-layout { display: grid; grid-template-columns: 1fr; gap: 1.5rem; margin-top: 1.5rem; }
        @media (min-width: 1024px) { .cfg-layout { grid-template-columns: 18rem minmax(0, 1fr); gap: 2rem; } }

        .cfg-nav { border: 1px solid var(--cfg-line); align-self: start; }
        .cfg-tab + .cfg-tab { border-top: 1px solid var(--cfg-line); }
        .cfg-tab-head { width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 1rem; font-size: .8125rem; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: var(--cfg-text); text-align: left; }
        .cfg-tab-head svg { width: 1rem; height: 1rem; transition: transform .15s; }
        .cfg-tab-head[aria-expanded="true"] svg { transform: rotate(180deg); }
        .cfg-items { background: var(--cfg-soft); border-top: 1px solid var(--cfg-line); }
        .cfg-item { display: block; padding: .85rem 1.25rem; font-size: .875rem; color: var(--cfg-text); border-left: 3px solid transparent; }
        .cfg-item:hover { background: rgb(0 0 0 / .03); }
        .dark .cfg-item:hover { background: rgb(255 255 255 / .05); }
        .cfg-item[aria-current="page"] { background: rgb(255 255 255); border-left-color: var(--cfg-accent); font-weight: 600; }
        .dark .cfg-item[aria-current="page"] { background: rgb(255 255 255 / .06); }

        .cfg-pane-title { font-size: 1.25rem; font-weight: 600; color: var(--cfg-text); margin-bottom: 1rem; }

        /* Etiquetas a la izquierda, alineadas a la derecha, con el alcance debajo (como Magento). */
        .cfg-pane .fi-fo-field-has-inline-label .fi-fo-field-label-col { justify-content: flex-end; text-align: right; }
        .cfg-pane .fi-fo-field-has-inline-label .fi-fo-field-label-ctn { justify-content: flex-end; }
        .cfg-pane .fi-fo-field-label-content { display: inline-flex; flex-direction: column; align-items: flex-end; line-height: 1.25; }
        .cfg-scope { display: block; font-size: .6875rem; font-weight: 400; color: var(--cfg-muted); margin-top: .125rem; }
        @media (max-width: 767px) { .cfg-pane .fi-fo-field-label-content { align-items: flex-start; } }
    </style>

    <div class="cfg">
        <form wire:submit="save">
            <div class="cfg-toolbar">
                <div class="cfg-scope-switch">
                    <strong>{{ __('Scope') }}:</strong>
                    <span>{{ __('Default Config') }}</span>
                    <span class="cfg-help" title="{{ __('Configuration is global: it applies to every store, channel and site.') }}">?</span>
                </div>

                <x-filament::button type="submit" size="lg">
                    {{ __('Save Config') }}
                </x-filament::button>
            </div>

            <div class="cfg-layout">
                <nav class="cfg-nav" aria-label="{{ __('Configuration') }}">
                    @foreach ($navigation as $group)
                        @php($isOpen = collect($group['items'])->contains(fn (array $item): bool => $item['slug'] === $current::slug()))
                        <div class="cfg-tab" x-data="{ open: {{ $isOpen ? 'true' : 'false' }} }">
                            <button type="button" class="cfg-tab-head" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                                <span>{{ __($group['tab']) }}</span>
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                            </button>
                            <div class="cfg-items" x-show="open" @unless ($isOpen) style="display: none" @endunless>
                                @foreach ($group['items'] as $item)
                                    <a href="{{ \Logistics\Settings\Presentation\Filament\Pages\Configuration::sectionUrl($item['slug']) }}"
                                       class="cfg-item"
                                       @if ($item['slug'] === $current::slug()) aria-current="page" @endif>
                                        {{ __($item['label']) }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </nav>

                <section class="cfg-pane">
                    <h2 class="cfg-pane-title">{{ __($current::label()) }}</h2>
                    {{ $this->form }}
                </section>
            </div>
        </form>
    </div>
</x-filament-panels::page>
