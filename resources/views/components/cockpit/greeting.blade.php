{{-- Encabezado de saludo del cockpit: eyebrow (fecha/contexto), título grande y subtítulo, con un
     slot `actions` a la derecha para CTAs (p. ej. "Nuevo envío"). --}}
@props(['eyebrow' => null, 'title', 'subtitle' => null])

<header class="flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="ck-greeting__eyebrow">{{ $eyebrow }}</p>
        @endif
        <h1 class="ck-greeting__title mt-1">{{ $title }}</h1>
        @if ($subtitle)
            <p class="ck-greeting__sub mt-1">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex items-center gap-2 shrink-0">{{ $actions }}</div>
    @endisset
</header>
