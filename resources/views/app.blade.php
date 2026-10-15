<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- El título lo gestiona Inertia por página (<Head> en Vue); este es el inicial. --}}
    <title inertia>{{ config('app.name', 'Dismac Logistics') }}</title>

    {{-- Rutas nombradas de Laravel disponibles en el cliente vía Ziggy (route() en Vue). --}}
    @routes

    @vite(['resources/js/app.js'])
    @inertiaHead
</head>
<body class="min-h-screen bg-neutral-950 font-sans text-neutral-100 antialiased">
    @inertia
</body>
</html>
