{{--
    Email de invitación a calificar la entrega (MÓDULO 1 — Calificación de envío).
    Texto en español neutro, marca DisTrack, CERO CDN (todo inline). El enlace lleva el token opaco; nunca
    se muestran UUIDs. Las estrellas enlazan a la misma página con `?r=N` para preseleccionar la nota; la
    calificación se confirma SIEMPRE en la página (no por GET), así un prefetch del cliente de correo no
    registra nada.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DisTrack</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:Arial, Helvetica, sans-serif; color:#18181b;">
    <table role="presentation" style="width:100%; border-collapse:collapse; border-spacing:0; background-color:#f4f4f5; padding:24px 0;">
        <tr>
            <td style="padding:0; text-align:center;">
                <table role="presentation" style="width:100%; max-width:480px; border-collapse:collapse; border-spacing:0; margin:0 auto; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e4e4e7;">
                    <tr>
                        <td style="background-color:#E30613; padding:20px 32px;">
                            <span style="color:#ffffff; font-size:20px; font-weight:bold; letter-spacing:0.5px;">DisTrack</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 16px; font-size:18px; color:#18181b;">
                                @if ($customerName)
                                    Hola {{ $customerName }}, ¿cómo te fue con tu entrega?
                                @else
                                    ¿Cómo te fue con tu entrega?
                                @endif
                            </h1>
                            <p style="margin:0 0 20px; font-size:14px; line-height:1.6; color:#3f3f46;">
                                @if ($orderReference)
                                    Tu pedido <strong>{{ $orderReference }}</strong> ya fue entregado.
                                @else
                                    Tu pedido ya fue entregado.
                                @endif
                                Tu opinión nos ayuda a mejorar el servicio. Calificá tu entrega del 1 al 5:
                            </p>
                            <div style="text-align:center; margin:8px 0 24px;">
                                @for ($star = 1; $star <= 5; $star++)
                                    <a href="{{ $surveyUrl }}?r={{ $star }}"
                                       style="display:inline-block; width:44px; height:44px; line-height:44px; margin:0 3px; font-size:22px; color:#ffffff; background-color:#E30613; border-radius:8px; text-decoration:none;">&#9733;</a>
                                @endfor
                            </div>
                            <div style="text-align:center; margin:0 0 20px;">
                                <a href="{{ $surveyUrl }}"
                                   style="display:inline-block; font-size:14px; font-weight:bold; color:#ffffff; background-color:#18181b; border-radius:8px; padding:12px 28px; text-decoration:none;">Calificar mi entrega</a>
                            </div>
                            @if ($expiresAtLabel)
                                <p style="margin:0 0 8px; font-size:13px; line-height:1.6; color:#71717a;">
                                    El enlace está disponible hasta el {{ $expiresAtLabel }}.
                                </p>
                            @endif
                            <p style="margin:0; font-size:13px; line-height:1.6; color:#71717a;">
                                Si ya calificaste, podés ignorar este correo.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px; border-top:1px solid #e4e4e7;">
                            <p style="margin:0; font-size:12px; color:#a1a1aa;">Este es un mensaje automático de DisTrack. Por favor no respondas a este correo.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
