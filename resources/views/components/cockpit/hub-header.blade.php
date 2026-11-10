{{-- Cabecera de un Hub 360: eyebrow (tipo de entidad), título grande, subtítulo (referencia legible), y
     slots `status` (badge) y `actions` a la derecha. NUNCA UUID en el subtítulo. --}}
@props(['eyebrow' => null, 'title', 'subtitle' => null])
<div class="ck-hub__head">
    <div class="min-w-0">
        @if ($eyebrow)<p class="ck-card__eyebrow">{{ $eyebrow }}</p>@endif
        <h1 class="ck-hub__title @if ($eyebrow) mt-1 @endif">{{ $title }}</h1>
        @if ($subtitle)<p class="ck-hub__sub">{{ $subtitle }}</p>@endif
    </div>
    <div class="flex items-center gap-2 shrink-0 flex-wrap">
        @isset($status){{ $status }}@endisset
        @isset($actions){{ $actions }}@endisset
    </div>
</div>
