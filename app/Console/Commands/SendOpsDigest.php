<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Cockpit\ReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Digest diario de operaciones (Fase 5 — reportes y exportación): compone el resumen del día (entregas,
 * efectividad, excepciones, demanda) con {@see ReportBuilder} y lo envía por correo a administradores y
 * supervisores. Lo corre el scheduler (routes/console.php, diario 07:30) o se invoca a mano.
 *
 * Idempotente y tolerante: si no hay destinatarios o el mailer falla, no revienta; informa el resultado.
 * En dev MAIL_MAILER=log → el correo queda escrito en storage/logs (verificable).
 */
final class SendOpsDigest extends Command
{
    protected $signature = 'ops:digest';

    protected $description = 'Compose and email the daily operations digest to admins and supervisors';

    public function handle(ReportBuilder $reports): int
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            $this->warn('Ops digest: no admin/supervisor recipients found; nothing sent.');

            return self::SUCCESS;
        }

        $summary = $reports->dailySummary();
        $html = $this->composeHtml($summary);
        $subject = sprintf('DisTrack · Resumen de operaciones (%s)', now()->isoFormat('DD MMM YYYY'));

        try {
            Mail::html($html, static function ($message) use ($recipients, $subject): void {
                $message->to($recipients)->subject($subject);
            });
        } catch (Throwable $e) {
            $this->error('Ops digest: mail delivery failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Ops digest sent to %d recipient(s): received=%d delivered=%d effectiveness=%s failed=%d.',
            count($recipients),
            (int) $summary['received'],
            (int) $summary['delivered'],
            $summary['effectiveness_pct'] !== null ? $summary['effectiveness_pct'].'%' : 'n/a',
            (int) $summary['failed'],
        ));

        return self::SUCCESS;
    }

    /**
     * Correos de los usuarios con rol de oficina (admin/supervisor). Tolerante: lista vacía si falla.
     *
     * @return list<string>
     */
    private function recipients(): array
    {
        try {
            return array_values(DB::table('users')
                ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
                ->whereIn('user_roles.role', ['admin', 'supervisor'])
                ->whereNotNull('users.email')
                ->distinct()
                ->pluck('users.email')
                ->map(static fn ($e): string => (string) $e)
                ->filter(static fn (string $e): bool => $e !== '')
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * HTML del digest a partir del resumen diario.
     *
     * @param array<string, mixed> $s
     */
    private function composeHtml(array $s): string
    {
        $eff = $s['effectiveness_pct'] !== null ? $s['effectiveness_pct'].'%' : '—';
        $top = is_array($s['top_destination'] ?? null)
            ? htmlspecialchars((string) $s['top_destination']['label']).' ('.(int) $s['top_destination']['count'].' envíos)'
            : '—';

        $rows = '';
        foreach ((is_array($s['status'] ?? null) ? $s['status'] : []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows .= '<tr><td style="padding:4px 12px 4px 0">'.htmlspecialchars((string) $row['label'])
                .'</td><td style="padding:4px 0;text-align:right">'.(int) $row['count'].'</td></tr>';
        }

        return '<div style="font-family:Arial,sans-serif;color:#141414">'
            .'<h2 style="margin:0 0 4px">DisTrack · Resumen de operaciones</h2>'
            .'<p style="margin:0 0 16px;color:#666">Últimos '.(int) $s['window_days'].' días</p>'
            .'<ul style="margin:0 0 16px;padding-left:18px">'
            .'<li>Envíos recibidos: <b>'.(int) $s['received'].'</b></li>'
            .'<li>Entregas confirmadas: <b>'.(int) $s['delivered'].'</b></li>'
            .'<li>Efectividad de entrega: <b>'.$eff.'</b> ('.(int) $s['failed'].' fallidas)</li>'
            .'<li>Nodo con más demanda: <b>'.$top.'</b></li>'
            .'</ul>'
            .'<h3 style="margin:0 0 6px">Distribución por estado</h3>'
            .'<table style="border-collapse:collapse">'.$rows.'</table>'
            .'<p style="margin:16px 0 0;color:#999;font-size:12px">Generado automáticamente por DisTrack OS.</p>'
            .'</div>';
    }
}
