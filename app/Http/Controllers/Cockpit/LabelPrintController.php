<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cockpit;

use App\Enums\Role;
use App\Models\User;
use App\Support\Cockpit\LabelLifecycle;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Logistics\Labels\Domain\Contract\LabelSymbologyProvider;
use Logistics\Labels\Presentation\Support\LabelPreviewRenderer;

/**
 * Página IMPRIMIBLE de etiquetas para el admin: renderiza una o varias etiquetas en una hoja lista para
 * mandar a la impresora (normal o de pegatinas) con el diálogo del navegador. Respeta la SIMBOLOGÍA
 * configurada (solo QR, solo Zebra, o ambos) + el código único. Registra en la bitácora quién IMPRIMIÓ y
 * con qué tipo de impresora (control total del ciclo de vida). Solo oficina.
 */
final class LabelPrintController
{
    public function __construct(
        private readonly LabelPreviewRenderer $renderer,
        private readonly LabelLifecycle $lifecycle,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->hasAnyRole(Role::Admin, Role::Supervisor)) {
            abort(403);
        }

        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('ids', '')))));
        $printer = $request->query('printer') === 'sticker' ? 'sticker' : 'normal';

        if ($ids === []) {
            abort(404);
        }

        $symbology = app(LabelSymbologyProvider::class)->current(new DateTimeImmutable);

        $rows = DB::table('labels_emitted')->whereIn('label_id', $ids)->whereNull('revoked_at')
            ->get(['label_id', 'signed_json']);

        $labels = [];
        foreach ($rows as $r) {
            $labelId = (string) $r->label_id;
            // El SKU vive dentro del JSON firmado (campo `sku`), no es columna. Fallback: "Producto" (nunca vacío).
            $decoded = json_decode((string) $r->signed_json, true);
            $sku = is_array($decoded) && is_string($decoded['sku'] ?? null) && $decoded['sku'] !== '' ? $decoded['sku'] : __('Product');
            $labels[] = [
                'label_id' => $labelId,
                'sku' => $sku,
                'qr' => $symbology->includesQr() ? $this->renderer->qr((string) $r->signed_json) : '',
                'barcode' => $symbology->includesBarcode() ? $this->renderer->barcode($labelId) : '',
            ];

            // Bitácora: quién imprimió esta etiqueta y con qué impresora.
            $this->lifecycle->record(
                labelId: $labelId,
                event: 'printed',
                actor: (string) ($user->email ?? ''),
                actorName: is_string($user->name) ? $user->name : (string) ($user->email ?? ''),
                meta: ['printer' => $printer],
            );
        }

        return view('filament.labels.print-sheet', [
            'labels' => $labels,
            'printer' => $printer,
            'symbology' => $symbology->value,
        ]);
    }
}
