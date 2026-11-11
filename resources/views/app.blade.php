<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- El título real lo pone cada página Vue (Head de Inertia); este es el respaldo. --}}
    <title inertia>{{ config('app.name', 'DisTrack') }}</title>

    {{-- Bundle de la SPA (Vite). Las URLs que la app necesita viajan como props de Inertia. --}}
    @vite(['resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full antialiased bg-neutral-950 text-neutral-100">
    @inertia
</body>
</html>
