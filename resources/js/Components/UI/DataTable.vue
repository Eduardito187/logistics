<script setup>
// Tabla de datos reutilizable. Define columnas (`{ key, label, align }`) y filas; por cada columna
// puedes pasar un slot con nombre `cell-<key>` para render custom (badges, links, etc.). Si `clickable`,
// emite `row-click` con la fila (para navegar al detalle). Diseñada para que cada módulo la reuse.
defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: () => [] },
    rowKey: { type: String, default: 'id' },
    clickable: { type: Boolean, default: false },
    emptyText: { type: String, default: 'Sin registros.' },
});

defineEmits(['row-click']);

const alignClass = (align) => ({ right: 'text-right', center: 'text-center' }[align] || 'text-left');
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-neutral-800">
        <table class="min-w-full divide-y divide-neutral-800 text-sm">
            <thead class="bg-neutral-900/70">
                <tr>
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        class="px-4 py-2.5 font-medium text-neutral-400"
                        :class="alignClass(col.align)"
                    >
                        {{ col.label }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-800/70">
                <tr v-if="rows.length === 0">
                    <td :colspan="columns.length" class="px-4 py-8 text-center text-neutral-500">
                        {{ emptyText }}
                    </td>
                </tr>
                <tr
                    v-for="row in rows"
                    :key="row[rowKey]"
                    class="bg-neutral-900/30 transition"
                    :class="clickable ? 'cursor-pointer hover:bg-neutral-800/50' : ''"
                    @click="clickable && $emit('row-click', row)"
                >
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        class="px-4 py-3 text-neutral-200"
                        :class="alignClass(col.align)"
                    >
                        <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                            {{ row[col.key] ?? '—' }}
                        </slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
