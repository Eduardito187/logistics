{{--
    Agenda del equipo (Fase 5 CRM) — todas las tareas/seguimientos abiertos de cualquier ficha 360, para
    trabajar el día: vencidas primero, hoy, próximas, sin fecha. Cada tarea enlaza a la ficha de su entidad
    y se marca hecha acá. Capa `.distrack-cockpit`, español directo. NUNCA UUID visible.
--}}
@php
    $t = $this->tasks;
    $counts = [
        'overdue' => count($t['overdue']),
        'today' => count($t['today']),
        'upcoming' => count($t['upcoming']),
        'nodate' => count($t['nodate']),
    ];

    $group = function (string $key, string $title, string $eyebrowTone) use ($t) {
        return [$t[$key] ?? [], $title, $eyebrowTone];
    };
@endphp

<x-filament-panels::page>
    <div class="distrack-cockpit">
        <div class="ck-hub__head">
            <div class="ck-hub__id">
                <div style="min-width:0">
                    <div class="ck-hub__title">Agenda</div>
                    <div class="ck-hub__sub">{{ $t['total'] }} {{ $t['total'] === 1 ? 'tarea abierta' : 'tareas abiertas' }} del equipo</div>
                </div>
            </div>
            <div class="ck-hub__aside">
                @if($counts['overdue'] > 0)
                    <span class="ck-pill ck-pill--danger"><span class="ck-pill__dot"></span>{{ $counts['overdue'] }} vencidas</span>
                @else
                    <span class="ck-pill ck-pill--positive"><span class="ck-pill__dot"></span>Nada vencido</span>
                @endif
            </div>
        </div>

        <div class="ck-grid ck-grid--4" style="margin-top:1.1rem">
            <div class="ck-stat {{ $counts['overdue'] > 0 ? 'ck-stat--danger' : '' }}">
                <div class="ck-stat__label">Vencidas</div>
                <div class="ck-stat__value">{{ $counts['overdue'] }}</div>
            </div>
            <div class="ck-stat ck-stat--warning">
                <div class="ck-stat__label">Para hoy</div>
                <div class="ck-stat__value">{{ $counts['today'] }}</div>
            </div>
            <div class="ck-stat ck-stat--agent">
                <div class="ck-stat__label">Próximas</div>
                <div class="ck-stat__value">{{ $counts['upcoming'] }}</div>
            </div>
            <div class="ck-stat">
                <div class="ck-stat__label">Sin fecha</div>
                <div class="ck-stat__value">{{ $counts['nodate'] }}</div>
            </div>
        </div>

        @php
            $sections = [
                ['overdue', 'Vencidas', 'danger'],
                ['today', 'Para hoy', 'warning'],
                ['upcoming', 'Próximas', ''],
                ['nodate', 'Sin fecha', ''],
            ];
        @endphp

        @if($t['total'] === 0)
            <div class="ck-empty ck-section">
                <div class="ck-empty__title">Agenda al día</div>
                <div class="ck-empty__hint">No hay tareas abiertas. Las tareas se crean desde la pestaña «Notas» de cualquier ficha 360.</div>
            </div>
        @else
            @foreach($sections as [$key, $title, $tone])
                @if(count($t[$key]) > 0)
                    <div class="ck-card ck-section">
                        <div class="ck-card__eyebrow">{{ $title }} · {{ count($t[$key]) }}</div>
                        <div class="ck-events" style="margin-top:.6rem">
                            @foreach($t[$key] as $task)
                                <div class="ck-event" wire:key="task-{{ $task['id'] }}">
                                    <span class="ck-event__ic {{ $tone === 'danger' ? 'ck-event__ic--red' : ($tone === 'warning' ? 'ck-event__ic--amber' : '') }}"></span>
                                    <span class="ck-event__tx">
                                        {{ $task['body'] }}
                                        <small>
                                            {{ $task['entity'] }}:
                                            @if($task['url'])<a href="{{ $task['url'] }}" wire:navigate class="ck-link">{{ $task['subject'] }}</a>@else{{ $task['subject'] }}@endif
                                            · {{ $task['author'] }}@if($task['due']) · vence {{ $task['due'] }}@endif
                                        </small>
                                    </span>
                                    <button type="button" wire:click="completeTask({{ $task['id'] }})" class="ck-pill ck-pill--positive" style="border:0;cursor:pointer">Marcar hecha</button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        @endif
    </div>
</x-filament-panels::page>
