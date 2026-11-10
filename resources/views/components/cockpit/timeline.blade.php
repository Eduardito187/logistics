{{--
    Línea de tiempo (actividad) de una entidad para su ficha 360: lista cronológica de hechos de dominio
    (outbox) y acciones de administración (auditoría), con etiqueta legible y cuándo. Datos reales vía
    App\Support\Cockpit\EntityTimeline; si no hay eventos, muestra el vacío elegante (no inventa nada).

    @param list<array{label: string, when: string, kind: string, actor: ?string}> $events
--}}
@props(['events' => []])

<div class="ck-events">
    @forelse($events as $e)
        <div class="ck-event">
            <span class="ck-event__ic {{ ($e['kind'] ?? '') === 'admin' ? 'ck-event__ic--agent' : '' }}"></span>
            <span class="ck-event__tx">
                {{ $e['label'] ?? 'Evento' }}
                @if(!empty($e['actor']))<small>{{ $e['actor'] }}</small>@endif
            </span>
            <span class="ck-event__when">{{ $e['when'] ?? '' }}</span>
        </div>
    @empty
        <div class="ck-event">
            <span class="ck-event__tx" style="color:var(--ck-ink-3)">Sin actividad registrada todavía.</span>
        </div>
    @endforelse
</div>
