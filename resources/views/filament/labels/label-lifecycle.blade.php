{{-- Línea de tiempo del CICLO DE VIDA de la etiqueta: generación, impresión, cada escaneo (por etapa) y
     revocación — con el ACTOR (quién) y la hora de cada evento. Datos del servicio LabelLifecycle (bitácora
     `label_events` + escaneos de `execution_scan_events` unidos por el bulto). --}}
@php
    /** @var \Logistics\Labels\Infrastructure\Persistence\Eloquent\EmittedLabelRecord $record */
    $record = $getRecord();
    $timeline = app(\App\Support\Cockpit\LabelLifecycle::class)->timeline((string) $record->label_id);
    $tone = ['ok' => '#16a34a', 'info' => '#38bdf8', 'danger' => '#ef4444'];
@endphp

@if(empty($timeline))
    <p style="color:var(--ck-muted,#8a8a99);font-size:.9rem">
        {{ __('No lifecycle events yet for this label.') }}
    </p>
@else
    <ol style="list-style:none;margin:0;padding:0;position:relative">
        @foreach($timeline as $i => $e)
            <li style="display:flex;gap:.9rem;padding:.2rem 0 .9rem">
                {{-- Riel + punto --}}
                <div style="display:flex;flex-direction:column;align-items:center">
                    <span style="width:.7rem;height:.7rem;border-radius:999px;background:{{ $tone[$e['tone']] ?? '#8a8a99' }};flex:none;margin-top:.3rem"></span>
                    @if(!$loop->last)
                        <span style="flex:1;width:2px;background:rgba(255,255,255,.1);margin-top:.15rem"></span>
                    @endif
                </div>
                {{-- Contenido --}}
                <div style="min-width:0;flex:1">
                    <div style="display:flex;flex-wrap:wrap;align-items:baseline;gap:.5rem">
                        <span style="font-weight:600;color:var(--ck-text,#e4e4e7)">{{ $e['event'] }}</span>
                        <span style="font-size:.8rem;color:var(--ck-muted,#8a8a99)">· {{ $e['when'] }}</span>
                    </div>
                    <div style="font-size:.85rem;color:var(--ck-text,#c9c9d4);margin-top:.1rem">
                        <span style="color:var(--ck-muted,#8a8a99)">{{ __('By') }}:</span> <strong>{{ $e['actor'] }}</strong>
                        @if($e['detail'] !== '')
                            <span style="color:var(--ck-muted,#8a8a99)">— {{ $e['detail'] }}</span>
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ol>
@endif
