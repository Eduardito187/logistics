{{-- Tiras de pestañas para hubs. `tabs` = list<['key'=>, 'label'=>, 'n'=>?]>. NO declara su propio estado:
     usa la variable Alpine `t` del contenedor de la página (p. ej. <div x-data="{ t: 'resumen' }">), de modo
     que los paneles (x-show="t === 'clave'") reaccionan al mismo estado. --}}
@props(['tabs' => []])
<nav {{ $attributes->class(['ck-tabs']) }} role="tablist">
    @foreach ($tabs as $tab)
        <button type="button" role="tab" class="ck-tab"
            :class="{ 'ck-tab--on': t === @js($tab['key']) }"
            :aria-selected="t === @js($tab['key'])"
            @click="t = @js($tab['key'])">
            {{ $tab['label'] ?? $tab['key'] }}@if (isset($tab['n']) && $tab['n'] !== null)<span class="ck-tab__n">{{ $tab['n'] }}</span>@endif
        </button>
    @endforeach
</nav>
