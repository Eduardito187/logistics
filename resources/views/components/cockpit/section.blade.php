{{-- Sección titulada dentro de una página cockpit. Slot `actions` opcional a la derecha del título. --}}
@props(['title' => null, 'eyebrow' => null])
<section {{ $attributes->class(['ck-section']) }}>
    @if ($title || $eyebrow || isset($actions))
        <div class="ck-section__head">
            <div class="min-w-0">
                @if ($eyebrow)<p class="ck-card__eyebrow">{{ $eyebrow }}</p>@endif
                @if ($title)<h2 class="ck-section__title">{{ $title }}</h2>@endif
            </div>
            @isset($actions)<div class="flex items-center gap-2 shrink-0">{{ $actions }}</div>@endisset
        </div>
    @endif
    {{ $slot }}
</section>
