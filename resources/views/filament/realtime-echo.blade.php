{{-- Tiempo real del admin (Fase 6): carga Laravel Echo + Pusher (contra Soketi self-host) para el push
     de posiciones de flota en la Torre de Control. El script se auto-desactiva si no hay credenciales de
     cliente (VITE_PUSHER_APP_KEY); si no, las superficies siguen con su polling. Solo para oficina. --}}
@vite(['resources/js/echo.js'])
