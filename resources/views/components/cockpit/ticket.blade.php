{{-- Tarjeta de un ítem en el pipeline. `tone`: late|warn|done|agent. `ref` arriba, `title` legible, `meta` abajo.
     Renderiza como <button> (acción Livewire) o <a> si se pasa href. NUNCA mostrar UUID: usar ref legible. --}}
@props(['ref' => null, 'title' => null, 'meta' => null, 'tone' => null, 'href' => null])
@php $cls = ['ck-ticket', 'ck-ticket--'.$tone => $tone]; @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($cls) }} wire:navigate>
        @if ($ref)<div class="ck-ticket__ref">{{ $ref }}</div>@endif
        @if ($title)<div class="ck-ticket__title">{{ $title }}</div>@endif
        @if ($meta)<div class="ck-ticket__meta">{{ $meta }}</div>@endif
        {{ $slot }}
    </a>
@else
    <button type="button" {{ $attributes->class($cls) }}>
        @if ($ref)<div class="ck-ticket__ref">{{ $ref }}</div>@endif
        @if ($title)<div class="ck-ticket__title">{{ $title }}</div>@endif
        @if ($meta)<div class="ck-ticket__meta">{{ $meta }}</div>@endif
        {{ $slot }}
    </button>
@endif
