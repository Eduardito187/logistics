{{-- Correo del código OTP de reseteo de contraseña. Texto en español neutro, marca DisTrack. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DisTrack</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:Arial, Helvetica, sans-serif; color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px; background-color:#ffffff; border-radius:12px; overflow:hidden; border:1px solid #e4e4e7;">
                    <tr>
                        <td style="background-color:#E30613; padding:20px 32px;">
                            <span style="color:#ffffff; font-size:20px; font-weight:bold; letter-spacing:0.5px;">DisTrack</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 16px; font-size:18px; color:#18181b;">Restablece tu contraseña</h1>
                            <p style="margin:0 0 20px; font-size:14px; line-height:1.6; color:#3f3f46;">
                                Recibimos una solicitud para restablecer la contraseña de tu cuenta. Usa el
                                siguiente código para continuar:
                            </p>
                            <div style="text-align:center; margin:24px 0;">
                                <span style="display:inline-block; font-size:32px; font-weight:bold; letter-spacing:8px; color:#18181b; background-color:#f4f4f5; border-radius:8px; padding:16px 24px;">{{ $code }}</span>
                            </div>
                            <p style="margin:0 0 8px; font-size:13px; line-height:1.6; color:#71717a;">
                                El código vence en {{ $expiresInMinutes }} minutos.
                            </p>
                            <p style="margin:0; font-size:13px; line-height:1.6; color:#71717a;">
                                Si no solicitaste este cambio, ignora este correo; tu contraseña seguirá siendo la misma.
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
