<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\CockpitNote;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Throwable;

/**
 * Notas y seguimientos (actividad CRM) para una ficha 360 (ViewRecord) de cualquier entidad. Da al hub un
 * muro de notas/tareas reutilizable: alta, marcar tarea hecha/reabrir y borrar, enlazado a la entidad por
 * (subject_type, subject_id). Se usa junto al componente blade `<x-cockpit.notes :notes="$this->notes" />`
 * (que se enlaza a estas props y acciones). Persiste en `cockpit_notes` ({@see CockpitNote}).
 *
 * La página que lo usa debe declarar `noteSubjectType()` (p. ej. 'vehicle', 'shipment'); el id del sujeto
 * sale de la clave del registro. Alcance de oficina (el hub ya está gateado por su Resource).
 *
 * @mixin ViewRecord
 */
trait HasEntityNotes
{
    /** Formulario de nota/seguimiento (Livewire). */
    public string $noteBody = '';

    public string $noteKind = CockpitNote::KIND_NOTE;

    public ?string $noteDue = null;

    /** Tipo lógico de la entidad para las notas (lo define cada hub, p. ej. 'vehicle'). */
    abstract protected function noteSubjectType(): string;

    /** Identificador del sujeto: la clave del registro de la ficha. */
    protected function noteSubjectId(): string
    {
        return (string) $this->getRecord()->getKey();
    }

    /**
     * Notas y seguimientos de la entidad, tareas pendientes primero y luego por fecha descendente.
     *
     * @return list<array{id: int, body: string, kind: string, author: ?string, when: ?string, due: ?string, done: bool}>
     */
    #[Computed]
    public function notes(): array
    {
        try {
            $rows = CockpitNote::query()
                ->where('subject_type', $this->noteSubjectType())
                ->where('subject_id', $this->noteSubjectId())
                ->orderByRaw('done_at is null desc')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();

            $out = [];
            foreach ($rows as $n) {
                $out[] = [
                    'id' => (int) $n->getKey(),
                    'body' => (string) $n->body,
                    'kind' => (string) $n->kind,
                    'author' => \is_string($n->author_label) && $n->author_label !== '' ? $n->author_label : null,
                    'when' => $n->created_at?->isoFormat('DD MMM HH:mm'),
                    'due' => $n->due_on?->isoFormat('DD MMM YYYY'),
                    'done' => $n->done_at !== null,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Agrega una nota o seguimiento a la entidad. */
    public function addNote(): void
    {
        $body = trim($this->noteBody);
        if ($body === '') {
            Notification::make()->title('Escribí una nota antes de guardar.')->warning()->send();

            return;
        }

        $kind = $this->noteKind === CockpitNote::KIND_TASK ? CockpitNote::KIND_TASK : CockpitNote::KIND_NOTE;
        $user = Filament::auth()->user();

        CockpitNote::query()->create([
            'author_id' => Filament::auth()->id(),
            'author_label' => $user?->getAttribute('name'),
            'subject_type' => $this->noteSubjectType(),
            'subject_id' => $this->noteSubjectId(),
            'body' => $body,
            'kind' => $kind,
            'due_on' => $kind === CockpitNote::KIND_TASK && \is_string($this->noteDue) && $this->noteDue !== '' ? $this->noteDue : null,
        ]);

        $this->noteBody = '';
        $this->noteDue = null;
        $this->noteKind = CockpitNote::KIND_NOTE;
        unset($this->notes);

        Notification::make()->title('Nota guardada.')->success()->send();
    }

    /** Marca una tarea como hecha (o la reabre), solo dentro de la entidad actual. */
    public function toggleTask(int $noteId): void
    {
        $note = $this->ownedNote($noteId);
        if ($note === null) {
            return;
        }

        $note->done_at = $note->done_at === null ? now() : null;
        $note->save();
        unset($this->notes);
    }

    /** Borra una nota de la entidad actual. */
    public function deleteNote(int $noteId): void
    {
        $this->ownedNote($noteId)?->delete();
        unset($this->notes);

        Notification::make()->title('Nota eliminada.')->success()->send();
    }

    /** Recupera una nota solo si pertenece a la entidad en pantalla (evita tocar notas de otro sujeto). */
    private function ownedNote(int $noteId): ?CockpitNote
    {
        return CockpitNote::query()
            ->whereKey($noteId)
            ->where('subject_type', $this->noteSubjectType())
            ->where('subject_id', $this->noteSubjectId())
            ->first();
    }
}
