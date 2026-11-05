{{-- Línea de tiempo horizontal de paradas de una ruta: cada parada con su punto (done/current/upcoming),
     etiqueta y ETA. `stops` es una lista de ['label'=>, 'eta'=>?, 'state'=>'done'|'current'|'upcoming']. --}}
@props(['stops' => []])

<div class="ck-timeline">
    @foreach ($stops as $stop)
        <div @class([
            'ck-stop',
            'ck-stop--done' => ($stop['state'] ?? '') === 'done',
            'ck-stop--current' => ($stop['state'] ?? '') === 'current',
        ])>
            <span class="ck-stop__dot" aria-hidden="true"></span>
            <div class="ck-stop__label" title="{{ $stop['label'] ?? '' }}">{{ $stop['label'] ?? '' }}</div>
            @if (! empty($stop['eta']))
                <div class="ck-stop__eta">{{ $stop['eta'] }}</div>
            @endif
        </div>
    @endforeach
</div>
