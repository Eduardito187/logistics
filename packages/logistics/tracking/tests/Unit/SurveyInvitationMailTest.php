<?php

declare(strict_types=1);

use Logistics\Tracking\Presentation\Mail\SurveyInvitationMail;

// Mailable de invitación a calificar: asunto con/sin referencia de la orden y datos que pasa a la vista.

it('arma el asunto con la referencia humana de la orden', function (): void {
    $mail = new SurveyInvitationMail(
        surveyUrl: 'https://distrack.test/t/abc123',
        customerName: 'Ana',
        orderReference: 'PED-0001',
        expiresAtLabel: '15 de noviembre',
    );

    expect($mail->envelope()->subject)->toBe('DisTrack · ¿Cómo te fue con tu entrega del pedido PED-0001?');
});

it('arma un asunto genérico cuando no hay referencia de la orden', function (): void {
    $mail = new SurveyInvitationMail(surveyUrl: 'https://distrack.test/t/abc123');

    expect($mail->envelope()->subject)->toBe('DisTrack · ¿Cómo te fue con tu entrega?');
});

it('pasa el enlace y los datos del cliente a la vista', function (): void {
    $mail = new SurveyInvitationMail(
        surveyUrl: 'https://distrack.test/t/abc123',
        customerName: 'Ana',
        orderReference: 'PED-0001',
        expiresAtLabel: '15 de noviembre',
    );

    $content = $mail->content();

    expect($content->view)->toBe('tracking::email.survey-invitation')
        ->and($content->with)->toBe([
            'surveyUrl' => 'https://distrack.test/t/abc123',
            'customerName' => 'Ana',
            'orderReference' => 'PED-0001',
            'expiresAtLabel' => '15 de noviembre',
        ]);
});
