<script setup>
// Login propio del SPA (Inertia). Pantalla de ingreso de la app web /app; ya no se reusa el login de
// Filament. NO lleva AppLayout: es una pantalla independiente, por eso layout: null.
import { Head, useForm } from '@inertiajs/vue3';

defineOptions({ layout: null });

// Formulario de ingreso: email, contraseña y "recordarme". Postea a app.login.store.
const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('app.login.store'), {
        // No conservamos la contraseña en memoria tras el intento.
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Ingresar" />

    <div class="flex min-h-screen items-center justify-center bg-neutral-950 px-4 py-12">
        <div class="w-full max-w-sm">
            <!-- Marca Dismac -->
            <div class="mb-8 text-center">
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-xl text-xl font-black tracking-tight text-white"
                    style="background-color: #e2231a"
                >
                    D
                </div>
                <h1 class="mt-4 text-lg font-semibold text-white">Plataforma logística</h1>
                <p class="mt-1 text-sm text-neutral-400">Ingresa para continuar</p>
            </div>

            <!-- Card de ingreso -->
            <div class="rounded-2xl border border-neutral-800 bg-neutral-900 p-6 shadow-xl">
                <form class="space-y-5" @submit.prevent="submit">
                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-medium text-neutral-300">
                            Correo electrónico
                        </label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            name="email"
                            autocomplete="email"
                            autofocus
                            required
                            class="w-full rounded-lg border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-white placeholder-neutral-500 focus:border-[#e2231a] focus:outline-none focus:ring-1 focus:ring-[#e2231a]"
                            placeholder="tu@dismac.com.bo"
                        />
                        <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-400">
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-medium text-neutral-300">
                            Contraseña
                        </label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                            class="w-full rounded-lg border border-neutral-700 bg-neutral-950 px-3 py-2 text-sm text-white placeholder-neutral-500 focus:border-[#e2231a] focus:outline-none focus:ring-1 focus:ring-[#e2231a]"
                            placeholder="••••••••"
                        />
                        <p v-if="form.errors.password" class="mt-1.5 text-sm text-red-400">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-neutral-300 select-none">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            name="remember"
                            class="h-4 w-4 rounded border-neutral-700 bg-neutral-950 text-[#e2231a] focus:ring-[#e2231a]"
                        />
                        Recordarme
                    </label>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-lg px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
                        style="background-color: #e2231a"
                    >
                        {{ form.processing ? 'Ingresando…' : 'Ingresar' }}
                    </button>
                </form>
            </div>

            <p class="mt-6 text-center text-xs text-neutral-600">Dismac · Logística</p>
        </div>
    </div>
</template>
