<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;
use Logistics\Tracking\Presentation\Mail\SurveyInvitationMail;

/**
 * MÓDULO 1 — Calificación de envío: el cron horario `logistics:tracking:send-survey-invitations` manda un
 * email con el enlace público de calificación a cada envío ENTREGADO que espera invitación, lo marca como
 * invitado (no reenvía), respeta el TTL del enlace y nunca filtra UUIDs al cliente. Corre contra
 * logistics_test. El mailer se finge con Mail::fake(): no se toca sendmail ni se depende de credenciales.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
    Mail::fake();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Seguimiento entregado listo para invitación (entregado hace 2 h, con email y sin invitación previa).
 *
 * @param array<string, mixed> $overrides
 */
function invitationDeliveredRow(array $overrides = []): TrackingRecord
{
    return TrackingRecord::factory()->delivered()->create(array_merge([
        'shipment_id' => TrackingId::generate()->toString(),
        'order_reference' => 'ORD-'.random_int(100000, 999999),
        'delivered_at' => now()->subHours(2),
        'customer_email' => 'cliente@example.com',
        'rating_requested_at' => null,
    ], $overrides));
}

it('emails one survey invitation per eligible delivery and marks it as requested', function (): void {
    $row = invitationDeliveredRow(['order_reference' => 'ORD-RATE-1']);

    $this->artisan('logistics:tracking:send-survey-invitations')->assertSuccessful();

    Mail::assertSent(SurveyInvitationMail::class, 1);
    Mail::assertSent(
        SurveyInvitationMail::class,
        fn (SurveyInvitationMail $mail): bool => $mail->hasTo('cliente@example.com')
            && $mail->orderReference === 'ORD-RATE-1'
            && str_contains($mail->surveyUrl, $row->token),
    );

    expect(TrackingRecord::query()->findOrFail($row->id)->rating_requested_at)->not->toBeNull();
});

it('does not resend on a second run (idempotent)', function (): void {
    invitationDeliveredRow();

    $this->artisan('logistics:tracking:send-survey-invitations')->assertSuccessful();
    $this->artisan('logistics:tracking:send-survey-invitations')->assertSuccessful();

    Mail::assertSent(SurveyInvitationMail::class, 1);
});

it('never puts the shipment UUID in the survey link', function (): void {
    $row = invitationDeliveredRow();

    $this->artisan('logistics:tracking:send-survey-invitations')->assertSuccessful();

    Mail::assertSent(
        SurveyInvitationMail::class,
        fn (SurveyInvitationMail $mail): bool => !str_contains($mail->surveyUrl, (string) $row->shipment_id),
    );
});

it('respects the public link TTL and skips deliveries older than the window', function (): void {
    // Default TTL = 72 h desde la entrega: una entrega de hace 100 h ya tiene el enlace vencido.
    invitationDeliveredRow(['delivered_at' => now()->subHours(100), 'customer_email' => 'viejo@example.com']);
    invitationDeliveredRow(['delivered_at' => now()->subHours(2), 'customer_email' => 'fresco@example.com']);

    $this->artisan('logistics:tracking:send-survey-invitations')->assertSuccessful();

    Mail::assertSent(SurveyInvitationMail::class, 1);
    Mail::assertSent(
        SurveyInvitationMail::class,
        fn (SurveyInvitationMail $mail): bool => $mail->hasTo('fresco@example.com'),
    );
    Mail::assertNotSent(
        SurveyInvitationMail::class,
        fn (SurveyInvitationMail $mail): bool => $mail->hasTo('viejo@example.com'),
    );
});

it('does nothing when there are no deliveries awaiting a rating', function (): void {
    $this->artisan('logistics:tracking:send-survey-invitations')->assertSuccessful();

    Mail::assertNothingSent();
});
