{{--
    Home del CHOFER (DisTrack OS, Fase 4 — homes por rol). "Mi día": vehículo + tripulación de hoy, ruta
    vigente con progreso de paradas y próxima parada. Informativo (el chofer opera desde su navegación).
    Datos de App\Support\Cockpit\DriverHome. Se renderiza dentro de la superficie cockpit de ControlTower.
--}}
@php /** @var array<string, mixed> $h */ @endphp
<div class="ck-section">
    <div class="ck-hub__head" style="margin-bottom:1rem">
        <div style="min-width:0">
            <div class="ck-hub__title" style="font-size:1.4rem">Hola, {{ $h['driver_name'] }}</div>
            <div class="ck-hub__sub">{{ $h['has_crew'] ? 'Este es tu día de hoy.' : 'Hoy no tenés una tripulación asignada.' }}</div>
        </div>
        <div class="ck-hub__aside">
            @if($h['vehicle_plate'])
                <span class="ck-pill ck-pill--agent"><span class="ck-pill__dot"></span>{{ $h['vehicle_plate'] }}</span>
            @endif
        </div>
    </div>

    @if($h['route'])
        @php $r = $h['route']; @endphp
        <div class="ck-grid ck-grid--4">
            <div class="ck-stat ck-stat--agent">
                <div class="ck-stat__label">Paradas de hoy</div>
                <div class="ck-stat__value">{{ $r['total'] }}</div>
            </div>
            <div class="ck-stat ck-stat--positive">
                <div class="ck-stat__label">Completadas</div>
                <div class="ck-stat__value">{{ $r['completed'] }}</div>
            </div>
            <div class="ck-stat">
                <div class="ck-stat__label">Pendientes</div>
                <div class="ck-stat__value">{{ $r['remaining'] }}</div>
            </div>
            <div class="ck-stat ck-stat--warning">
                <div class="ck-stat__label">Próxima parada</div>
                <div class="ck-stat__value" style="font-size:1rem">{{ $r['next']['label'] ?? '—' }}</div>
                <div class="ck-stat__hint">{{ $r['next']['eta'] ?? 'Ruta por iniciar' }}</div>
            </div>
        </div>

        @if(count($r['stops']))
            <div class="ck-card" style="margin-top:1rem">
                <div class="ck-card__eyebrow">Tu ruta de hoy</div>
                <div class="ck-timeline" style="margin-top:1.1rem">
                    @foreach(array_slice($r['stops'], 0, 10) as $stop)
                        <div class="ck-stop {{ $stop['state'] === 'done' ? 'ck-stop--done' : ($stop['state'] === 'warn' ? 'ck-stop--current' : '') }}">
                            <div class="ck-stop__dot"></div>
                            <div class="ck-stop__label">{{ $stop['label'] }}</div>
                            <div class="ck-stop__eta">{{ $stop['eta'] ?? '—' }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div class="ck-card">
            <div class="ck-empty">
                <div class="ck-empty__title">Sin ruta abierta</div>
                <div class="ck-empty__hint">Cuando se publique tu ruta del día, la vas a ver acá con tus paradas.</div>
            </div>
        </div>
    @endif

    @if(count($h['helpers']))
        <div class="ck-card" style="margin-top:1rem">
            <div class="ck-card__eyebrow">Tu tripulación</div>
            <div class="ck-hub__pills" style="margin-top:.6rem">
                @foreach($h['helpers'] as $helper)
                    <span class="ck-pill">{{ $helper }}</span>
                @endforeach
            </div>
        </div>
    @endif
</div>
