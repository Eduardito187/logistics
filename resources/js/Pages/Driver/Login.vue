<script setup>
import { Head, useForm } from '@inertiajs/vue3';

// Login del chofer: sesión web estándar, página propia en Vue.
const form = useForm({ email: '', password: '' });
const submit = () => form.post('/app/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head><title>Ingreso chofer</title></Head>

    <div class="flex min-h-screen items-center justify-center bg-neutral-950 px-4 text-neutral-100">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center gap-2">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg" style="background:#e30613">
                    <svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2.4">
                        <path d="M3 7h11v8H3z" /><path d="M14 10h4l3 3v2h-7z" /><circle cx="7" cy="17" r="1.6" /><circle cx="17" cy="17" r="1.6" />
                    </svg>
                </span>
                <div>
                    <p class="text-lg font-extrabold leading-none tracking-tight">DisTrack</p>
                    <p class="text-xs text-neutral-400">App de reparto</p>
                </div>
            </div>

            <form class="space-y-4 rounded-2xl border border-white/10 bg-neutral-900/60 p-5 shadow-xl" @submit.prevent="submit">
                <h1 class="text-base font-semibold">Ingresá a tu ruta</h1>

                <div>
                    <label class="mb-1 block text-xs text-neutral-400" for="email">Correo</label>
                    <input
                        id="email" v-model="form.email" type="email" autocomplete="username" inputmode="email"
                        class="w-full rounded-xl border border-white/10 bg-neutral-950 px-3 py-2.5 outline-none focus:border-white/30"
                        placeholder="chofer@dismac.test"
                    />
                </div>

                <div>
                    <label class="mb-1 block text-xs text-neutral-400" for="password">Contraseña</label>
                    <input
                        id="password" v-model="form.password" type="password" autocomplete="current-password"
                        class="w-full rounded-xl border border-white/10 bg-neutral-950 px-3 py-2.5 outline-none focus:border-white/30"
                        placeholder="••••••••"
                    />
                </div>

                <p v-if="form.errors.email" class="text-sm text-red-400">{{ form.errors.email }}</p>

                <button
                    type="submit" :disabled="form.processing"
                    class="w-full rounded-xl py-2.5 font-semibold text-white transition disabled:opacity-60"
                    style="background:#e30613"
                >
                    {{ form.processing ? 'Ingresando…' : 'Ingresar' }}
                </button>
            </form>
        </div>
    </div>
</template>
