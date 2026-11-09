{{--
    Home del PREPARADOR (DisTrack OS, Fase 4 — homes por rol). "Mi cola de preparación": envíos aún no
    ejecutados que hay que preparar, con foco en los de hoy. Informativo. Datos de
    App\Support\Cockpit\PickerHome. Se renderiza dentro de la superficie cockpit de ControlTower.
--}}
@php
    /** @var array<string, mixed> $h */
    $statusLabel = static fn (string $s): string => match (strtoupper($s)) {
        'RECEIVED' => 'Recibido',
        'PLANNED' => 'Planificado',
        default => ucfirst(strtolower($s)),
    };
@endphp
<div class="ck-section">
    <div class="ck-hub__head" style="margin-bottom:1rem">
        <div style="min-width:0">
            <div class="ck-hub__title" style="font-size:1.4rem">Preparación</div>
            <div class="ck-hub__sub">Envíos por preparar antes de salir a ruta</div>
        </div>
        <div class="ck-hub__aside">
            <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>{{ number_format($h['today']) }} para hoy</span>
        </div>
    </div>

    <div class="ck-grid ck-grid--4">
        <div class="ck-stat ck-stat--agent">
            <div class="ck-stat__label">Por preparar</div>
            <div class="ck-stat__value">{{ number_format($h['to_prepare']) }}</div>
            <div class="ck-stat__hint">cola total</div>
        </div>
        <div class="ck-stat ck-stat--warning">
            <div class="ck-stat__label">Para hoy</div>
            <div class="ck-stat__value">{{ number_format($h['today']) }}</div>
        </div>
        <div class="ck-stat">
            <div class="ck-stat__label">Recibidos</div>
            <div class="ck-stat__value">{{ number_format($h['received']) }}</div>
        </div>
        <div class="ck-stat">
            <div class="ck-stat__label">Planificados</div>
            <div class="ck-stat__value">{{ number_format($h['planned']) }}</div>
        </div>
    </div>

    <div class="ck-card" style="margin-top:1rem">
        <div class="ck-card__eyebrow">Próximos a preparar</div>
        <div class="ck-events" style="margin-top:.6rem">
            @forelse($h['queue'] as $s)
                <div class="ck-event">
                    <span class="ck-event__ic {{ strtoupper($s['status']) === 'RECEIVED' ? 'ck-event__ic--amber' : '' }}"></span>
                    <span class="ck-event__tx">
                        {{ $s['reference'] }}
                        @if($s['delivery_date'])<small>Entrega: {{ $s['delivery_date'] }}</small>@endif
                    </span>
                    <span class="ck-event__when">{{ $statusLabel($s['status']) }}</span>
                </div>
            @empty
                <div class="ck-empty">
                    <div class="ck-empty__title">Nada por preparar</div>
                    <div class="ck-empty__hint">Cuando lleguen envíos para preparar, los vas a ver acá.</div>
                </div>
            @endforelse
        </div>
    </div>
</div>
