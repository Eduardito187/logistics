{{-- Botón del cockpit. variant: agent|approve|ghost|brand. size: sm. Renderiza <a> si hay href, si no <button>. --}}
@props(['variant' => 'ghost', 'size' => null, 'href' => null, 'block' => false])
@php $cls = ['ck-btn', 'ck-btn--'.$variant => $variant, 'ck-btn--sm' => $size === 'sm', 'ck-btn--block' => $block]; @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($cls) }} wire:navigate>{{ $slot }}</a>
@else
    <button type="button" {{ $attributes->class($cls) }}>{{ $slot }}</button>
@endif
