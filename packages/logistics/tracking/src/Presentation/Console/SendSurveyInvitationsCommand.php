<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Console;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Facades\Mail;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Application\Command\MarkRatingRequested\MarkRatingRequested;
use Logistics\Tracking\Application\Contract\PublicLinkTtl;
use Logistics\Tracking\Application\Query\ListDeliveredAwaitingRating\ListDeliveredAwaitingRating;
use Logistics\Tracking\Application\ReadModel\DeliveredAwaitingRatingView;
use Logistics\Tracking\Presentation\Mail\SurveyInvitationMail;
use Throwable;

/**
 * Cron HORARIO de la calificación de envío (MÓDULO 1): invita por email a calificar la entrega.
 *
 * Toma los envíos ENTREGADOS que todavía esperan calificación (read model `DeliveredAwaitingRatingView`,
 * que ya excluye los sin email, los ya invitados y los ya calificados), les manda el enlace público de
 * calificación y los marca como invitados (`MarkRatingRequested`) para no reenviar.
 *
 * Idempotente: el read model filtra `rating_requested_at IS NULL`, así que un reenvío del cron no vuelve a
 * listar al que ya fue invitado. El email se marca SOLO si salió bien; si el mailer falla, el envío queda
 * pendiente para la próxima corrida (fire-and-forget, nunca bloquea el flujo).
 *
 * Respeta el TTL del enlace (`tracking.public_link_ttl_hours`): la ventana de invitación no supera el TTL,
 * así nunca se manda un enlace ya vencido. Con TTL ≤ 0 (enlace sin vencimiento) se usa una ventana por
 * defecto de {@see PublicLinkTtl::DEFAULT_HOURS} horas para acotar el barrido.
 *
 * El comando NO envía el correo por SMTP directo en el request: usa el mailer de Laravel (sendmail nativo
 * en prod, `log` en dev), invocado desde el scheduler fuera de cualquier petición de usuario.
 */
final class SendSurveyInvitationsCommand extends Command
{
    protected $signature = 'logistics:tracking:send-survey-invitations
        {--limit=100 : Maximum number of invitations to send in this run (1..500)}
        {--dry-run : List the candidates without sending email nor marking them as invited}';

    protected $description = 'Email delivered shipments awaiting a rating with the public survey link (hourly)';

    public function handle(
        QueryBus $queryBus,
        CommandBus $commandBus,
        PublicLinkTtl $ttl,
        Clock $clock,
        UrlGenerator $urls,
    ): int {
        $limit = $this->intOption('limit', 100);
        $dryRun = $this->option('dry-run') === true;

        $ttlHours = $ttl->hours();
        // La ventana de invitación no puede exceder el TTL del enlace: fuera de él el enlace ya venció y el
        // email sería inútil. Con TTL desactivado (≤ 0) el enlace no vence: se usa un barrido acotado.
        $windowHours = $ttlHours > 0 ? $ttlHours : PublicLinkTtl::DEFAULT_HOURS;
        $since = $clock->now()->sub(new DateInterval('PT'.$windowHours.'H'));

        /** @var list<DeliveredAwaitingRatingView> $candidates */
        $candidates = $queryBus->ask(new ListDeliveredAwaitingRating(since: $since, limit: $limit));

        if ($candidates === []) {
            $this->info('No delivered shipments are awaiting a rating invitation.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($candidates as $candidate) {
            $url = $this->surveyUrl($urls, $candidate->trackingToken);
            $expiresAt = $this->expiresAtLabel($candidate->deliveredAt, $ttlHours);

            if ($dryRun) {
                $this->line('[dry-run] '.$candidate->customerEmail.' → '.$url);
                $sent++;

                continue;
            }

            try {
                Mail::to($candidate->customerEmail)->send(new SurveyInvitationMail(
                    surveyUrl: $url,
                    customerName: $candidate->customerName,
                    orderReference: $candidate->orderReference,
                    expiresAtLabel: $expiresAt,
                ));
            } catch (Throwable $exception) {
                // No se marca: el envío vuelve a listarse la próxima hora. El error no tumba la corrida.
                $failed++;
                $this->warn('Could not email '.$candidate->shipmentId.': '.$exception->getMessage());

                continue;
            }

            // Reclamo idempotente: el UPDATE condicional del store evita una segunda invitación.
            $commandBus->dispatch(new MarkRatingRequested(
                shipmentId: $candidate->shipmentId,
                requestedAt: $clock->now()->format(DateTimeImmutable::ATOM),
            ));
            $sent++;
        }

        $this->table(
            ['Candidates', $dryRun ? 'Listed (dry-run)' : 'Invited', 'Failed'],
            [[count($candidates), $sent, $failed]],
        );

        return self::SUCCESS;
    }

    /**
     * URL absoluta de la página pública de calificación para el token. Prefiere la ruta con nombre del
     * contexto; si no está registrada, arma el camino conocido `/t/{token}` sobre APP_URL.
     */
    private function surveyUrl(UrlGenerator $urls, string $token): string
    {
        try {
            return $urls->route('tracking.public.page', ['token' => $token], true);
        } catch (Throwable) {
            return rtrim((string) config('app.url'), '/').'/t/'.$token;
        }
    }

    /**
     * Fecha de vencimiento del enlace, ya formateada para el email. Null si el TTL está desactivado (el
     * enlace no vence) o si la fecha de entrega no se puede interpretar.
     */
    private function expiresAtLabel(string $deliveredAt, int $ttlHours): ?string
    {
        if ($ttlHours <= 0) {
            return null;
        }

        try {
            $delivered = new DateTimeImmutable($deliveredAt);
        } catch (Throwable) {
            return null;
        }

        return $delivered
            ->add(new DateInterval('PT'.$ttlHours.'H'))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('d/m/Y H:i');
    }

    /** Lee una opción entera positiva con respaldo al default si viene vacía o inválida. */
    private function intOption(string $name, int $default): int
    {
        $raw = $this->option($name);
        if (!is_string($raw) || trim($raw) === '' || !ctype_digit(trim($raw))) {
            return $default;
        }

        $value = (int) trim($raw);

        return $value > 0 ? $value : $default;
    }
}
