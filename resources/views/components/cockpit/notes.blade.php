{{--
    Muro de notas y seguimientos (actividad CRM) reutilizable en cualquier superficie cockpit cuyo
    componente Livewire use el trait App\Filament\Concerns\HasEntityNotes (o exponga las mismas props y
    acciones): noteBody / noteKind / noteDue + addNote() / toggleTask(id) / deleteNote(id). Las directivas
    wire:* se enlazan al componente Livewire que lo renderiza (los componentes Blade se compilan inline).

    @param list<array{id:int, body:string, kind:string, author:?string, when:?string, due:?string, done:bool}> $notes
--}}
@props(['notes' => []])

<div>
    <div class="ck-card ck-section">
        <div class="ck-card__eyebrow">Nueva nota o seguimiento</div>
        <div style="margin-top:.7rem;display:flex;flex-direction:column;gap:.6rem">
            <textarea wire:model="noteBody" rows="2" placeholder="Escribí una nota, un acuerdo o un recordatorio…"
                      style="width:100%;background:var(--ck-surface-2,#1b1b1b);color:var(--ck-ink,#eee);border:1px solid var(--ck-line,#333);border-radius:10px;padding:.7rem .9rem;font:inherit;resize:vertical"></textarea>
            <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center">
                <select wire:model="noteKind"
                        style="background:var(--ck-surface-2,#1b1b1b);color:var(--ck-ink,#eee);border:1px solid var(--ck-line,#333);border-radius:10px;padding:.5rem .7rem;font:inherit">
                    <option value="note">Nota</option>
                    <option value="task">Tarea (con vencimiento)</option>
                </select>
                <input type="date" wire:model="noteDue"
                       style="background:var(--ck-surface-2,#1b1b1b);color:var(--ck-ink,#eee);border:1px solid var(--ck-line,#333);border-radius:10px;padding:.5rem .7rem;font:inherit">
                <button type="button" wire:click="addNote" wire:loading.attr="disabled"
                        style="background:var(--ck-red,#e30613);color:#fff;border:0;border-radius:10px;padding:.55rem 1.1rem;font:inherit;font-weight:600;cursor:pointer">
                    Guardar
                </button>
            </div>
        </div>
    </div>

    <div class="ck-card ck-section">
        <div class="ck-card__eyebrow">Muro de actividad</div>
        <div class="ck-events" style="margin-top:.6rem">
            @forelse($notes as $n)
                <div class="ck-event" wire:key="note-{{ $n['id'] }}">
                    <span class="ck-event__ic {{ $n['kind'] === 'task' ? ($n['done'] ? 'ck-event__ic--green' : 'ck-event__ic--amber') : '' }}"></span>
                    <span class="ck-event__tx" style="{{ $n['done'] ? 'opacity:.55;text-decoration:line-through' : '' }}">
                        {{ $n['body'] }}
                        <small>
                            {{ $n['author'] ?: 'Oficina' }}{{ $n['when'] ? ' · '.$n['when'] : '' }}
                            @if($n['kind'] === 'task' && $n['due'])· vence {{ $n['due'] }}@endif
                        </small>
                    </span>
                    <span style="display:inline-flex;gap:.5rem;align-items:center">
                        @if($n['kind'] === 'task')
                            <button type="button" wire:click="toggleTask({{ $n['id'] }})" class="ck-link" style="background:none;border:0;cursor:pointer">
                                {{ $n['done'] ? 'Reabrir' : 'Marcar hecha' }}
                            </button>
                        @endif
                        <button type="button" wire:click="deleteNote({{ $n['id'] }})" class="ck-link" style="background:none;border:0;cursor:pointer;color:var(--ck-danger,#e5484d)">Borrar</button>
                    </span>
                </div>
            @empty
                <div class="ck-empty"><div class="ck-empty__title">Sin actividad</div><div class="ck-empty__hint">Agregá la primera nota o seguimiento acá.</div></div>
            @endforelse
        </div>
    </div>
</div>
