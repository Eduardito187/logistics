<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Dispatch;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Psr\Log\LoggerInterface;

/**
 * Reacción de NOTIFICACIONES ante el resumen de documentos de flota por vencer / vencidos
 * (fleet.documents_expiry_alerted) que llega por el outbox: avisa al SUPERVISOR de operaciones con un
 * correo consolidado del barrido (cuántos vencidos, cuántos por vencer y el detalle).
 *
 * A diferencia de los avisos al cliente (anclados a un envío, con cascada de canales), esta es una
 * alerta interna de operaciones que no cuelga de ningún envío, así que sale directo por el mailer de
 * Laravel (sendmail nativo del host). Si no hay email de supervisor configurado, deja el aviso en el log y no
 * falla: el relay lo da por despachado.
 *
 * Idempotencia: el outbox reentrega at-least-once; un correo de resumen duplicado es de bajo impacto
 * (es un digest diario) y no se de-duplica acá. Si en el futuro se quiere evitarlo, anclar un registro
 * por reference_date antes de enviar.
 */
final readonly class FleetDocumentExpiryNotificationDispatcher implements MessageDispatcher
{
    public function __construct(
        private LoggerInterface $logger,
        private ?string $supervisorEmail,
        private string $fromAddress,
        private string $fromName,
        private ?string $mailer = null,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'fleet.documents_expiry_alerted') {
            return;
        }

        $payload = $message->payload();

        $total = $this->asInt($payload['total_count'] ?? null);
        $expired = $this->asInt($payload['expired_count'] ?? null);
        $expiring = $this->asInt($payload['expiring_count'] ?? null);
        $referenceDate = is_string($payload['reference_date'] ?? null) ? $payload['reference_date'] : '';

        // Deja siempre rastro en el log de operaciones, haya o no email configurado.
        $this->logger->warning('Fleet document expiry alert', [
            'reference_date' => $referenceDate,
            'total' => $total,
            'expired' => $expired,
            'expiring' => $expiring,
        ]);

        if ($this->supervisorEmail === null || trim($this->supervisorEmail) === '') {
            return;
        }

        $subject = sprintf('Fleet documents expiring: %d (%d expired)', $total, $expired);
        $html = $this->renderHtml($referenceDate, $total, $expired, $expiring, $this->documentRows($payload));

        $mailer = $this->mailer !== null && $this->mailer !== '' ? Mail::mailer($this->mailer) : Mail::mailer();
        $email = $this->supervisorEmail;

        $mailer->html($html, function (Message $mail) use ($email, $subject): void {
            $mail->to($email)
                ->from($this->fromAddress, $this->fromName)
                ->subject($subject);
        });
    }

    /**
     * Extrae las filas de documentos del payload de forma defensiva (vienen del evento de fleet).
     *
     * @param  array<string, mixed>                                                                                           $payload
     * @return list<array{owner_type: string, owner_label: string, document_type: string, expires_on: string, expired: bool}>
     */
    private function documentRows(array $payload): array
    {
        /** @var mixed $raw */
        $raw = $payload['documents'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rows[] = [
                'owner_type' => is_string($item['owner_type'] ?? null) ? $item['owner_type'] : '',
                'owner_label' => is_string($item['owner_label'] ?? null) ? $item['owner_label'] : '',
                'document_type' => is_string($item['document_type'] ?? null) ? $item['document_type'] : '',
                'expires_on' => is_string($item['expires_on'] ?? null) ? $item['expires_on'] : '',
                'expired' => (bool) ($item['expired'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * Arma el HTML del correo del digest sin depender de una vista (alerta interna simple).
     *
     * @param list<array{owner_type: string, owner_label: string, document_type: string, expires_on: string, expired: bool}> $rows
     */
    private function renderHtml(string $referenceDate, int $total, int $expired, int $expiring, array $rows): string
    {
        $items = '';
        foreach ($rows as $row) {
            $status = $row['expired'] ? 'EXPIRED' : 'expiring';
            $items .= sprintf(
                '<li>[%s] %s — %s — %s (expires %s)</li>',
                htmlspecialchars($status, ENT_QUOTES),
                htmlspecialchars($row['owner_type'], ENT_QUOTES),
                htmlspecialchars($row['owner_label'], ENT_QUOTES),
                htmlspecialchars($row['document_type'], ENT_QUOTES),
                htmlspecialchars($row['expires_on'], ENT_QUOTES),
            );
        }

        return sprintf(
            '<p>Fleet document expiry scan for %s found %d document(s) in the alert window '
            .'(%d expired, %d upcoming).</p><ul>%s</ul>',
            htmlspecialchars($referenceDate, ENT_QUOTES),
            $total,
            $expired,
            $expiring,
            $items,
        );
    }

    private function asInt(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
