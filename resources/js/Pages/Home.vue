<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';

// Landing del cliente: ingresa el código de su enlace de seguimiento y lo lleva a «Seguí tu pedido».
const code = ref('');

// Acepta tanto un código suelto como un enlace completo (…/app/track/XXXX): extrae el token.
const token = computed(() => {
    const v = code.value.trim();
    const m = v.match(/track\/([^/?#\s]+)/i);
    return (m ? m[1] : v).trim();
});

const go = () => {
    if (!token.value) return;
    router.visit(`/app/track/${encodeURIComponent(token.value)}`);
};
</script>

<template>
    <Head title="Seguí tu pedido" />

    <div class="min-h-screen bg-neutral-950 text-neutral-100">
        <header class="border-b border-white/10">
            <div class="mx-auto flex max-w-xl items-center gap-2 px-4 py-4">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg" style="background:#e30613">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2.4">
                        <path d="M3 7h11v8H3z" /><path d="M14 10h4l3 3v2h-7z" /><circle cx="7" cy="17" r="1.6" /><circle cx="17" cy="17" r="1.6" />
                    </svg>
                </span>
                <span class="text-lg font-extrabold tracking-tight">DisTrack</span>
            </div>
        </header>

        <main class="mx-auto max-w-xl px-4 py-12">
            <h1 class="text-3xl font-extrabold leading-tight">Seguí tu pedido en tiempo real</h1>
            <p class="mt-2 text-neutral-400">
                Ingresá el código de tu enlace de seguimiento (o pegá el enlace completo) y mirá dónde va tu entrega.
            </p>

            <form class="mt-6 flex flex-col gap-3 sm:flex-row" @submit.prevent="go">
                <input
                    v-model="code" type="text" autocomplete="off"
                    class="flex-1 rounded-xl border border-white/10 bg-neutral-900 px-4 py-3 outline-none focus:border-white/30"
                    placeholder="Pegá tu código o enlace de seguimiento"
                    aria-label="Código de seguimiento"
                />
                <button
                    type="submit" :disabled="!token"
                    class="rounded-xl px-5 py-3 font-semibold text-white transition disabled:opacity-50"
                    style="background:#e30613"
                >
                    Seguir pedido
                </button>
            </form>

            <ul class="mt-10 space-y-3 text-sm text-neutral-300">
                <li class="flex items-start gap-3">
                    <span class="mt-1 h-2 w-2 shrink-0 rounded-full" style="background:#16a34a"></span>
                    Mirá el estado de tu entrega y a cuántas paradas está tu pedido.
                </li>
                <li class="flex items-start gap-3">
                    <span class="mt-1 h-2 w-2 shrink-0 rounded-full" style="background:#38bdf8"></span>
                    Seguimiento en vivo cuando el camión está en camino.
                </li>
                <li class="flex items-start gap-3">
                    <span class="mt-1 h-2 w-2 shrink-0 rounded-full" style="background:#e30613"></span>
                    El detalle de los productos de tu pedido.
                </li>
            </ul>
        </main>
    </div>
</template>
