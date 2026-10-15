<script setup>
// Dropdown propio basado en DIV (sin <select> nativo), con estética de la SPA oscura y rojo de marca.
// API tipo v-model: el padre pasa `modelValue` y escucha `update:modelValue`. Acepta las opciones como
// lista `[{ value, label }]` o como objeto `{ value: label }`; se normaliza a una sola forma interna.
// Accesible: role listbox/option, aria-expanded, navegación por teclado (flechas, Enter, Escape) y
// cierre al hacer click fuera. Todo el texto visible (etiqueta, placeholder) lo provee el padre por props.
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    // Valor seleccionado (contrato v-model).
    modelValue: { type: [String, Number, null], default: null },
    // Opciones: array de { value, label } u objeto { value: label }.
    options: { type: [Array, Object], default: () => [] },
    // Texto cuando no hay selección.
    placeholder: { type: String, default: 'Selecciona una opción…' },
    // Etiqueta opcional encima del control.
    label: { type: String, default: null },
    // Deshabilita la interacción.
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

// Normaliza las opciones a `[{ value, label }]` sin importar la forma de entrada.
const normalizedOptions = computed(() => {
    const raw = props.options;
    if (Array.isArray(raw)) {
        return raw.map((opt) =>
            typeof opt === 'object' && opt !== null
                ? { value: opt.value, label: String(opt.label ?? opt.value) }
                : { value: opt, label: String(opt) },
        );
    }
    if (raw && typeof raw === 'object') {
        return Object.entries(raw).map(([value, label]) => ({ value, label: String(label) }));
    }
    return [];
});

// Etiqueta de la opción actualmente seleccionada (o null si no hay coincidencia).
const selectedLabel = computed(() => {
    const match = normalizedOptions.value.find((opt) => opt.value === props.modelValue);
    return match ? match.label : null;
});

// Estado de apertura e índice resaltado para navegación por teclado.
const open = ref(false);
const highlightedIndex = ref(-1);
const buttonRef = ref(null);
const rootRef = ref(null);

// Índice de la opción seleccionada dentro de la lista normalizada.
const selectedIndex = computed(() =>
    normalizedOptions.value.findIndex((opt) => opt.value === props.modelValue),
);

// Abre el panel y resalta la opción seleccionada (o la primera).
const openPanel = () => {
    if (props.disabled) {
        return;
    }
    open.value = true;
    highlightedIndex.value = selectedIndex.value >= 0 ? selectedIndex.value : 0;
};

const closePanel = () => {
    open.value = false;
    highlightedIndex.value = -1;
};

const togglePanel = () => {
    open.value ? closePanel() : openPanel();
};

// Selecciona una opción, emite el nuevo value y devuelve el foco al botón.
const selectOption = (index) => {
    const option = normalizedOptions.value[index];
    if (!option) {
        return;
    }
    emit('update:modelValue', option.value);
    closePanel();
    nextTick(() => buttonRef.value?.focus());
};

// Mueve el resaltado dentro de los límites de la lista.
const moveHighlight = (delta) => {
    const count = normalizedOptions.value.length;
    if (count === 0) {
        return;
    }
    const next = highlightedIndex.value + delta;
    highlightedIndex.value = Math.max(0, Math.min(count - 1, next < 0 ? 0 : next));
};

// Teclado sobre el botón/panel: flechas navegan, Enter selecciona, Escape cierra.
const onKeydown = (event) => {
    if (props.disabled) {
        return;
    }
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            open.value ? moveHighlight(1) : openPanel();
            break;
        case 'ArrowUp':
            event.preventDefault();
            open.value ? moveHighlight(-1) : openPanel();
            break;
        case 'Enter':
        case ' ':
            event.preventDefault();
            if (open.value) {
                selectOption(highlightedIndex.value);
            } else {
                openPanel();
            }
            break;
        case 'Escape':
            if (open.value) {
                event.preventDefault();
                closePanel();
            }
            break;
        default:
            break;
    }
};

// Cierra el panel al hacer click fuera del componente (listener global solo mientras está abierto).
const onDocumentClick = (event) => {
    if (rootRef.value && !rootRef.value.contains(event.target)) {
        closePanel();
    }
};

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('click', onDocumentClick);
    } else {
        document.removeEventListener('click', onDocumentClick);
    }
});

// Garantiza limpiar el listener si el componente se desmonta estando abierto.
onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick);
});
</script>

<template>
    <div ref="rootRef" class="relative">
        <label v-if="label" class="mb-1 block text-xs text-neutral-400">{{ label }}</label>

        <!-- Botón que muestra la etiqueta seleccionada o el placeholder, más el chevron. -->
        <button
            ref="buttonRef"
            type="button"
            role="combobox"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :disabled="disabled"
            class="flex w-full items-center justify-between gap-2 rounded-lg border border-neutral-700 bg-neutral-950 px-3 py-2 text-left text-sm text-neutral-100 transition focus:border-[#E2231A] focus:outline-none focus:ring-2 focus:ring-red-600/40 disabled:cursor-not-allowed disabled:opacity-50"
            @click="togglePanel"
            @keydown="onKeydown"
        >
            <span :class="selectedLabel ? 'text-neutral-100' : 'text-neutral-500'" class="truncate">
                {{ selectedLabel ?? placeholder }}
            </span>
            <svg
                class="h-4 w-4 shrink-0 text-neutral-400 transition-transform duration-150"
                :class="{ 'rotate-180': open }"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
            </svg>
        </button>

        <!-- Panel flotante con las opciones. Transición simple de aparición. -->
        <transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <div
                v-if="open"
                role="listbox"
                class="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-neutral-700 bg-neutral-900 py-1 shadow-lg"
            >
                <div
                    v-for="(opt, index) in normalizedOptions"
                    :key="opt.value"
                    role="option"
                    :aria-selected="opt.value === modelValue"
                    class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm transition"
                    :class="[
                        index === highlightedIndex ? 'bg-neutral-800' : '',
                        opt.value === modelValue ? 'font-medium text-[#E2231A]' : 'text-neutral-200',
                    ]"
                    @click="selectOption(index)"
                    @mouseenter="highlightedIndex = index"
                >
                    <span class="truncate">{{ opt.label }}</span>
                    <!-- Check de la opción seleccionada, con el rojo de marca. -->
                    <svg
                        v-if="opt.value === modelValue"
                        class="h-4 w-4 shrink-0 text-[#E2231A]"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0l-3.5-3.5a1 1 0 1 1 1.4-1.4l2.8 2.79 6.8-6.79a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div v-if="normalizedOptions.length === 0" class="px-3 py-2 text-sm text-neutral-500">
                    Sin opciones
                </div>
            </div>
        </transition>
    </div>
</template>
