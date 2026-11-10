<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CockpitNote;
use App\Support\Cockpit\CustomerHub;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Logistics\Shared\Presentation\Filament\Concerns\OfficeOnly;
use Throwable;

/**
 * Cliente 360 (Fase 4 CRM): la ficha completa del cliente como cockpit de detalle. Agrega — por nombre de
 * cliente (clave de agrupación, contrato compartido entre frentes) — sus métricas (pedidos, envíos,
 * efectividad, gasto histórico, satisfacción), sus pedidos comerciales (enlazados a su hub), sus envíos,
 * sus calificaciones, sus direcciones en un mapa, y un muro de NOTAS Y SEGUIMIENTOS (actividad humana del
 * CRM: anotar, recordar, dar seguimiento). Datos reales y tolerantes vía {@see CustomerHub}.
 *
 * Se accede por enlace desde el pedido/envío (?customer=<nombre>) y desde la lista de clientes; por eso no
 * se lista en el menú (shouldRegisterNavigation = false). Alcance de oficina (admin/supervisor). Usa la
 * capa de diseño `.distrack-cockpit`; el texto va en español directo, como el resto de las páginas cockpit.
 */
final class CustomerProfile extends Page
{
    use OfficeOnly;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected string $view = 'filament.pages.customer-profile';

    /** Nombre del cliente recibido por querystring (?customer=<nombre>); clave de agrupación y de notas. */
    public ?string $customer = null;

    /** Formulario de nota/seguimiento (Livewire). */
    public string $noteBody = '';

    public string $noteKind = CockpitNote::KIND_NOTE;

    public ?string $noteDue = null;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // Pertenece al frente Commerce (pedidos y clientes) aunque no se liste en el menú.
    public static function getNavigationGroup(): string
    {
        return __('Commerce');
    }

    /** Lee el nombre del cliente del querystring (?customer=<nombre>). Lo normaliza a null si no viene. */
    public function mount(): void
    {
        $customer = request()->query('customer');
        $this->customer = is_string($customer) && trim($customer) !== '' ? $customer : null;
    }

    public function getTitle(): string
    {
        return $this->customer ?? 'Cliente';
    }

    // El cockpit hace de encabezado; se suprime el header por defecto de la página.
    public function getHeading(): string
    {
        return '';
    }

    /**
     * Datos agregados del Cliente 360 (cross-context), memoizados durante el request de Livewire.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function hub(): array
    {
        if ($this->customer === null) {
            return [];
        }

        return app(CustomerHub::class)->overview($this->customer);
    }

    /**
     * Puntos geolocalizados del cliente para el mapa (direcciones con coordenadas, sin repetir).
     *
     * @return list<array{lat: float, lng: float, text: string}>
     */
    #[Computed]
    public function mapPoints(): array
    {
        if ($this->customer === null) {
            return [];
        }

        try {
            $points = [];
            $seen = [];
            foreach (DB::table('shipments')
                ->where('customer_name', $this->customer)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->get(['lat', 'lng', 'address_text']) as $row) {
                $key = $row->lat.','.$row->lng;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $points[] = [
                    'lat' => (float) $row->lat,
                    'lng' => (float) $row->lng,
                    'text' => (string) $row->address_text,
                ];
            }

            return $points;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Notas y seguimientos del cliente, pendientes primero (tareas abiertas), luego por fecha descendente.
     *
     * @return list<array{id: int, body: string, kind: string, author: ?string, when: ?string, due: ?string, done: bool}>
     */
    #[Computed]
    public function notes(): array
    {
        if ($this->customer === null) {
            return [];
        }

        try {
            $rows = CockpitNote::query()
                ->where('subject_type', 'customer')
                ->where('subject_id', $this->customer)
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

    /** Agrega una nota o seguimiento al cliente. */
    public function addNote(): void
    {
        if ($this->customer === null) {
            return;
        }

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
            'subject_type' => 'customer',
            'subject_id' => $this->customer,
            'body' => $body,
            'kind' => $kind,
            'due_on' => $kind === CockpitNote::KIND_TASK && is_string($this->noteDue) && $this->noteDue !== '' ? $this->noteDue : null,
        ]);

        $this->noteBody = '';
        $this->noteDue = null;
        $this->noteKind = CockpitNote::KIND_NOTE;
        unset($this->notes);

        Notification::make()->title('Nota guardada.')->success()->send();
    }

    /** Marca una tarea como hecha (o la reabre), solo dentro del cliente actual. */
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

    /** Borra una nota del cliente actual. */
    public function deleteNote(int $noteId): void
    {
        $this->ownedNote($noteId)?->delete();
        unset($this->notes);

        Notification::make()->title('Nota eliminada.')->success()->send();
    }

    /** Recupera una nota solo si pertenece al cliente en pantalla (evita tocar notas de otro sujeto). */
    private function ownedNote(int $noteId): ?CockpitNote
    {
        if ($this->customer === null) {
            return null;
        }

        return CockpitNote::query()
            ->whereKey($noteId)
            ->where('subject_type', 'customer')
            ->where('subject_id', $this->customer)
            ->first();
    }

    /** URL de la ficha para un cliente dado. Lo expone como enlace (contrato con otros frentes). */
    public static function urlFor(string $customerName): string
    {
        return self::getUrl(['customer' => $customerName]);
    }
}
