// Laravel Echo + Pusher (protocolo) contra el Soketi self-host (ADR 016, CERO Pusher.com/Google).
// Tiempo real del admin (Fase 6 — incremento enfocado): habilita el push de posiciones de flota a la
// Torre de Control. Se inicializa SOLO si hay credenciales de cliente (VITE_PUSHER_APP_KEY); si no, no
// hace nada y las superficies siguen con su polling (wire:poll / setInterval). Env-gated y reversible.
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const key = import.meta.env.VITE_PUSHER_APP_KEY;

if (key) {
    const host = import.meta.env.VITE_PUSHER_HOST || window.location.hostname;
    const port = Number(import.meta.env.VITE_PUSHER_PORT || 6001);
    const scheme = import.meta.env.VITE_PUSHER_SCHEME || 'http';
    const forceTLS = scheme === 'https';

    try {
        window.Echo = new Echo({
            broadcaster: 'pusher',
            key,
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS,
            encrypted: forceTLS,
            disableStats: true,
            enabledTransports: forceTLS ? ['wss'] : ['ws'],
        });
    } catch (e) {
        // El tiempo real es una mejora opcional: si no se puede inicializar, el admin sigue con polling.
        window.Echo = undefined;
    }
}
