// Laravel Echo + Pusher (protocolo) contra el Soketi self-host (ADR 016, CERO Pusher.com/Google).
// Tiempo real del admin: habilita el push de posiciones de flota a la Torre de Control.
//
// La configuración llega en RUNTIME, no en el build: el servidor la emite como <meta> en el <head> del
// panel (resources/views/filament/realtime-echo.blade.php) solo cuando el tiempo real está activo. Así la
// MISMA imagen sirve en cualquier entorno, sin variables VITE_PUSHER_* al construirla.
//
// El servidor de sockets se publica por el MISMO host y puerto que la web (nginx le pasa las conexiones
// WebSocket de `/app/<clave>`), así que por defecto se conecta al origen de la página: ws en http, wss en
// https. VITE_PUSHER_HOST / VITE_PUSHER_PORT / VITE_PUSHER_SCHEME siguen valiendo como override opcional
// de build (p. ej. un entorno de desarrollo que publica Soketi directo en el 6001).
//
// Si no hay configuración o algo falla, `window.Echo` queda undefined y las superficies siguen con su
// polling (wire:poll / setInterval).
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const AUTH_ENDPOINT = '/broadcasting/auth';

function meta(name) {
    const el = document.querySelector(`meta[name="${name}"]`);
    const value = el ? el.getAttribute('content') : null;

    return value && value.trim() !== '' ? value.trim() : null;
}

// Cabeceras CSRF para autorizar canales privados con la sesión web. Se leen en cada autorización (no una
// sola vez al cargar) para tomar siempre el token vigente. Respaldo: la cookie XSRF-TOKEN de Laravel.
function csrfHeaders() {
    const token = meta('csrf-token');
    if (token) {
        return { 'X-CSRF-TOKEN': token };
    }

    const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
    if (cookie) {
        try {
            return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
        } catch (e) {
            return {};
        }
    }

    return {};
}

// Destino del WebSocket: el mismo origen de la página, salvo override de build.
function socketTarget() {
    const env = import.meta.env || {};
    const pageSecure = window.location.protocol === 'https:';
    const secure = env.VITE_PUSHER_SCHEME ? env.VITE_PUSHER_SCHEME === 'https' : pageSecure;
    const pagePort = Number(window.location.port) || (pageSecure ? 443 : 80);
    const overridePort = Number(env.VITE_PUSHER_PORT);

    return {
        host: env.VITE_PUSHER_HOST || window.location.hostname,
        port: overridePort > 0 ? overridePort : pagePort,
        secure,
    };
}

function boot() {
    if (window.Echo) {
        return;
    }

    const key = meta('dismac-realtime-key');
    if (meta('dismac-realtime-enabled') !== '1' || !key) {
        return;
    }

    try {
        const target = socketTarget();

        window.Pusher = Pusher;
        window.Echo = new Echo({
            broadcaster: 'pusher',
            key,
            cluster: meta('dismac-realtime-cluster') || 'mt1',
            wsHost: target.host,
            wsPort: target.port,
            wssPort: target.port,
            forceTLS: target.secure,
            encrypted: target.secure,
            disableStats: true,
            enabledTransports: target.secure ? ['wss'] : ['ws'],
            // Canales privados: se autorizan contra la sesión web del panel (cookie + CSRF).
            authEndpoint: AUTH_ENDPOINT,
            auth: { headers: csrfHeaders() },
            channelAuthorization: {
                endpoint: AUTH_ENDPOINT,
                transport: 'ajax',
                headersProvider: csrfHeaders,
            },
        });
    } catch (e) {
        // El tiempo real es una mejora opcional: si no se puede inicializar, el admin sigue con polling.
        window.Echo = undefined;
    }
}

boot();

// En modo SPA (wire:navigate) el <head> se fusiona al navegar: si este módulo corrió antes de que
// llegaran los <meta> (p. ej. al entrar desde el login), se reintenta al terminar cada navegación.
document.addEventListener('livewire:navigated', boot);
