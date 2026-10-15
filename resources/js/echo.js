import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Cliente de tiempo real (Laravel Echo sobre protocolo Pusher) contra Soketi self-host (open-source,
// sin Pusher.com ni Google). Se inicializa SOLO si hay key configurada (VITE_PUSHER_APP_KEY); si no,
// window.Echo queda null y el SPA sigue funcionando sin tiempo real (degradación con gracia).
window.Pusher = Pusher;

const key = import.meta.env.VITE_PUSHER_APP_KEY;

window.Echo = key
    ? new Echo({
        broadcaster: 'pusher',
        key,
        wsHost: import.meta.env.VITE_PUSHER_HOST ?? window.location.hostname,
        wsPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 6001),
        wssPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 6001),
        forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
        disableStats: true,
    })
    : null;
