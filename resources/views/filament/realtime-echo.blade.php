{{-- Tiempo real del admin: configuración en RUNTIME + Laravel Echo/Pusher (contra Soketi self-host) para el
     push de posiciones de flota en la Torre de Control. Solo para oficina y solo con
     BROADCAST_CONNECTION=pusher; con el tiempo real apagado no se emite nada y las superficies siguen con
     su polling.

     La config viaja como <meta> (no como <script> inline) y la lee resources/js/echo.js: así la misma
     imagen sirve en cualquier entorno, sin variables VITE_PUSHER_* de build. Solo sale la clave PÚBLICA de
     la app de sockets; el secreto nunca llega al navegador (firma las suscripciones en /broadcasting/auth).
     El WebSocket va al MISMO origen de la página (nginx desvía `/app/<clave>` a Soketi), por eso no se
     publica host ni puerto. El <meta name="csrf-token"> lo emite el layout base de Filament. --}}
@php
    $realtimeKey = config('broadcasting.connections.pusher.key');
    $realtimeCluster = config('broadcasting.connections.pusher.options.cluster');
    $realtimeEnabled = config('broadcasting.default') === 'pusher' && is_string($realtimeKey) && $realtimeKey !== '';
@endphp
@if ($realtimeEnabled)
    <meta name="dismac-realtime-enabled" content="1">
    <meta name="dismac-realtime-key" content="{{ $realtimeKey }}">
    @if (is_string($realtimeCluster) && $realtimeCluster !== '')
        <meta name="dismac-realtime-cluster" content="{{ $realtimeCluster }}">
    @endif
    @vite(['resources/js/echo.js'])
@endif
