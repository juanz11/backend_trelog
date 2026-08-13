<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Restablecer contraseña · TR3SLOG</title>
</head>
<body style="margin:0;padding:0;background:#EEF4FC;font-family:Inter,'Noto Sans SC',system-ui,sans-serif;color:#10233F;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#EEF4FC;padding:40px 20px;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #DCE6F5;">
          <tr>
            <td style="background:#001B45;padding:32px 40px;text-align:center;">
              <div style="font-family:Montserrat,sans-serif;font-weight:800;font-size:26px;color:#ffffff;letter-spacing:-.02em;">
                TR3<span style="color:#D99A00;">S</span>LOG
              </div>
            </td>
          </tr>
          <tr>
            <td style="padding:40px;">
              <h1 style="font-family:Montserrat,sans-serif;font-weight:800;font-size:26px;color:#001B45;margin:0 0 18px;">Restablecer contraseña</h1>
              <p style="font-size:16px;line-height:1.7;color:#10233F;margin:0 0 24px;">Hemos recibido una solicitud para restablecer la contraseña de tu cuenta en <strong>TR3SLOG</strong>. Si fuiste tú, haz clic en el siguiente enlace:</p>

              <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0;">
                <tr>
                  <td style="background:#087CF0;border-radius:11px;text-align:center;">
                    <a href="{{ $resetUrl }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;border-radius:11px;">Crear nueva contraseña</a>
                  </td>
                </tr>
              </table>

              <p style="font-size:14px;line-height:1.6;color:#6C82A6;margin:24px 0 10px;">O copia este enlace en tu navegador:</p>
              <p style="font-size:13px;word-break:break-all;color:#001B45;margin:0 0 28px;font-family:monospace;">{{ $resetUrl }}</p>

              <div style="background:#FFF8E8;border:1px solid rgba(217,154,0,.4);border-radius:12px;padding:16px;">
                <p style="font-size:13px;line-height:1.6;color:#8A6300;margin:0;">Si no solicitaste este cambio, ignora este correo. Tu contraseña permanecerá segura.</p>
              </div>
            </td>
          </tr>
          <tr>
            <td style="background:#F4F8FD;padding:24px 40px;text-align:center;font-size:12px;color:#6C82A6;border-top:1px solid #DCE6F5;">
              © TR3SLOG — Logistics Solutions<br>
              1234 Logistics Way, Miami, FL 33101, USA
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
