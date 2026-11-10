{{-- Pill de estado: punto + texto, teñido por tono (positive/warning/danger/agent/neutral). --}}
@props(['tone' => 'neutral', 'dot' => true])

<span @class(['ck-pill', 'ck-pill--'.$tone => $tone !== 'neutral'])>
    @if ($dot)
        <span class="ck-pill__dot" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
