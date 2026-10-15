@props([
    // Etiqueta visible opcional sobre el control.
    'label' => null,
    // Texto cuando no hay selección.
    'placeholder' => null,
    // Opciones: array asociativo value => label, o lista de ['value' => ..., 'label' => ...].
    'options' => [],
    // Nombre de la propiedad Livewire a sincronizar. Si no se pasa, se intenta leer de wire:model.
    'property' => null,
])

@php
    // Normaliza las opciones a una lista uniforme [['value' => string, 'label' => string], ...].
    // Soporta tanto array asociativo (value => label) como lista de objetos/arrays {value, label}.
    $normalizedOptions = [];
    foreach ($options as $key => $option) {
        if (is_array($option) && array_key_exists('value', $option)) {
            $normalizedOptions[] = [
                'value' => (string) $option['value'],
                'label' => (string) ($option['label'] ?? $option['value']),
            ];
        } else {
            $normalizedOptions[] = [
                'value' => (string) $key,
                'label' => (string) $option,
            ];
        }
    }

    // Resuelve el nombre de la propiedad Livewire: prop explícito o, en su defecto, el wire:model del atributo.
    // Un componente anónimo no hereda wire:model directo, así que lo resolvemos manualmente.
    $resolvedProperty = $property ?? $attributes->wire('model')?->value();

    $placeholderText = $placeholder ?? __('Select an option');

    // Id único para enlazar label, botón y panel (accesibilidad).
    $uid = 'dismac-select-' . \Illuminate\Support\Str::random(8);
@endphp

<div
    {{ $attributes->whereDoesntStartWith('wire:model')->class('w-full') }}
    x-data="{
        open: false,
        // Propiedad Livewire a sincronizar.
        property: @js($resolvedProperty),
        // Opciones normalizadas disponibles para el teclado y el render.
        options: @js($normalizedOptions),
        // Valor seleccionado actual (string); se lee del estado Livewire al iniciar.
        value: '',
        // Índice resaltado durante la navegación con teclado.
        activeIndex: -1,

        init() {
            // Lee el valor inicial desde Livewire, replicando lo que haría wire:model.
            if (this.property) {
                const current = this.$wire.get(this.property);
                this.value = current === null || current === undefined ? '' : String(current);
            }
            // Mantiene el control en sincronía si Livewire cambia la propiedad (p. ej. loadZone/newZone).
            if (this.property) {
                this.$watch('open', () => {});
                this.$wire.$watch(this.property, (val) => {
                    this.value = val === null || val === undefined ? '' : String(val);
                });
            }
        },

        // Etiqueta visible según el valor seleccionado.
        get selectedLabel() {
            const match = this.options.find((o) => o.value === this.value);
            return match ? match.label : @js($placeholderText);
        },

        get hasSelection() {
            return this.options.some((o) => o.value === this.value);
        },

        toggle() {
            this.open ? this.close() : this.openPanel();
        },

        openPanel() {
            this.open = true;
            // Resalta la opción seleccionada, o la primera.
            const idx = this.options.findIndex((o) => o.value === this.value);
            this.activeIndex = idx >= 0 ? idx : 0;
            this.$nextTick(() => this.scrollActiveIntoView());
        },

        close() {
            this.open = false;
            this.activeIndex = -1;
            this.$nextTick(() => this.$refs.button?.focus());
        },

        // Selecciona una opción: fija el valor local y lo escribe en Livewire SIN roundtrip (deferido,
        // igual que un wire:model sin .live). El valor viaja con la próxima acción (Load/Save).
        select(index) {
            const option = this.options[index];
            if (!option) {
                return;
            }
            this.value = option.value;
            if (this.property) {
                this.$wire.set(this.property, option.value, false);
            }
            this.close();
        },

        move(delta) {
            if (!this.open) {
                this.openPanel();
                return;
            }
            const count = this.options.length;
            if (count === 0) {
                return;
            }
            this.activeIndex = (this.activeIndex + delta + count) % count;
            this.scrollActiveIntoView();
        },

        scrollActiveIntoView() {
            const el = this.$refs.panel?.querySelector('[data-index=\'' + this.activeIndex + '\']');
            el?.scrollIntoView({ block: 'nearest' });
        },
    }"
    x-id="['dismac-select-listbox']"
    @keydown.escape.stop="open && close()"
    class="w-full"
>
    @if ($label)
        <label :id="$id('dismac-select-listbox') + '-label'" class="text-sm font-medium">{{ $label }}</label>
    @endif

    <div class="relative mt-1" @click.outside="open && close()">
        {{-- Botón disparador: muestra la etiqueta seleccionada + chevron. NO es un <select> nativo. --}}
        <button
            type="button"
            x-ref="button"
            @click="toggle()"
            @keydown.arrow-down.prevent="move(1)"
            @keydown.arrow-up.prevent="move(-1)"
            @keydown.enter.prevent="open ? select(activeIndex) : openPanel()"
            @keydown.space.prevent="open ? select(activeIndex) : openPanel()"
            aria-haspopup="listbox"
            :aria-expanded="open.toString()"
            :aria-labelledby="$id('dismac-select-listbox') + '-label'"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-sm text-gray-950 shadow-sm transition focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
        >
            <span class="truncate" :class="hasSelection ? '' : 'text-gray-400 dark:text-gray-500'" x-text="selectedLabel"></span>
            <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
            </svg>
        </button>

        {{-- Panel de opciones basado en DIVs (no <option> nativos). --}}
        <div
            x-show="open"
            x-ref="panel"
            x-transition.opacity.duration.100ms
            role="listbox"
            :aria-labelledby="$id('dismac-select-listbox') + '-label'"
            x-cloak
            style="display: none;"
            class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg ring-1 ring-black/5 focus:outline-none dark:border-white/10 dark:bg-gray-900"
        >
            <template x-for="(option, index) in options" :key="option.value">
                <div
                    :data-index="index"
                    role="option"
                    :aria-selected="(option.value === value).toString()"
                    @click="select(index)"
                    @mousemove="activeIndex = index"
                    class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2"
                    :class="{
                        'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300': activeIndex === index,
                        'text-gray-950 dark:text-white': activeIndex !== index,
                    }"
                >
                    <span class="truncate" x-text="option.label"></span>
                    <svg x-show="option.value === value" class="h-4 w-4 shrink-0 text-primary-600 dark:text-primary-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.704 5.29a.75.75 0 0 1 .006 1.06l-7.5 7.5a.75.75 0 0 1-1.06 0l-3.5-3.5a.75.75 0 1 1 1.06-1.06l2.97 2.97 6.97-6.97a.75.75 0 0 1 1.054.006Z" clip-rule="evenodd" />
                    </svg>
                </div>
            </template>
        </div>
    </div>
</div>
