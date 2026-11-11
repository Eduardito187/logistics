<script setup>
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Enlace no encontrado" />

    <div class="flex min-h-screen items-center justify-center bg-neutral-950 px-4 text-neutral-100">
        <div class="max-w-sm text-center">
            <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-xl" style="background:#e30613">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#fff" stroke-width="2.2">
                    <path d="M3 7h11v8H3z" /><path d="M14 10h4l3 3v2h-7z" /><circle cx="7" cy="17" r="1.6" /><circle cx="17" cy="17" r="1.6" />
                </svg>
            </span>
            <h1 class="mt-5 text-xl font-bold">No encontramos este seguimiento</h1>
            <p class="mt-2 text-sm text-neutral-400">
                El enlace puede haber expirado o no ser válido. Revisá el enlace que te compartieron.
            </p>
        </div>
    </div>
</template>
