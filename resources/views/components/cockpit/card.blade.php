{{-- Tarjeta base del cockpit: fondo blanco, radio y sombra suave. Opcionalmente muestra un eyebrow y
     título, y admite un slot `head` para acciones/badges a la derecha del encabezado. --}}
@props(['eyebrow' => null, 'title' => null, 'raised' => false])

<section {{ $attributes->class(['ck-card', 'ck-card--raised' => $raised]) }}>
    @if ($eyebrow || $title || isset($head))
        <div class="flex items-start justify-between gap-3 mb-3">
            <div class="min-w-0">
                @if ($eyebrow)
                    <p class="ck-card__eyebrow">{{ $eyebrow }}</p>
                @endif
                @if ($title)
                    <h2 @class(['ck-card__title', 'mt-1' => $eyebrow])>{{ $title }}</h2>
                @endif
            </div>
            @isset($head)
                <div class="flex items-center gap-2 shrink-0">{{ $head }}</div>
            @endisset
        </div>
    @endif

    {{ $slot }}
</section>
