{{-- Columna de un pipeline: título + conteo + cuerpo con tickets. Slot `foot` opcional (p. ej. una acción). --}}
@props(['title', 'count' => null])
<div {{ $attributes->class(['ck-col']) }}>
    <div class="ck-col__head"><span>{{ $title }}</span>@if ($count !== null)<span class="ck-col__n">{{ $count }}</span>@endif</div>
    <div class="ck-col__body">{{ $slot }}</div>
    @isset($foot)<div class="mt-2">{{ $foot }}</div>@endisset
</div>
