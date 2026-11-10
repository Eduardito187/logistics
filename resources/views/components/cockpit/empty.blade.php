{{-- Estado vacío elegante (en vez de una tabla/espacio en blanco). --}}
@props(['title' => 'Nada por aquí', 'hint' => null])
<div {{ $attributes->class(['ck-empty']) }}>
    <p class="ck-empty__title">{{ $title }}</p>
    @if ($hint)<p class="ck-empty__hint">{{ $hint }}</p>@endif
    {{ $slot }}
</div>
