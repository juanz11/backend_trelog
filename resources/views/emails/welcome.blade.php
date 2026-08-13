<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bienvenido a TR3SLOG</title>
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
              <h1 style="font-family:Montserrat,sans-serif;font-weight:800;font-size:26px;color:#001B45;margin:0 0 18px;">¡Bienvenido, {{ $user->name }}!</h1>
              <p style="font-size:16px;line-height:1.7;color:#10233F;margin:0 0 20px;">Gracias por crear tu cuenta en <strong>TR3SLOG</strong>. Ya puedes acceder a tus envíos, cotizaciones y documentos desde el portal.</p>

              <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0;">
                <tr>
                  <td style="background:#001B45;border-radius:12px;padding:8px 8px 8px 18px;vertical-align:middle;">
                    <span style="display:inline-block;width:6px;height:6px;background:#D99A00;border-radius:50%;margin-right:10px;vertical-align:middle;"></span>
                    <span style="color:#ffffff;font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;vertical-align:middle;">Correo</span>
                  </td>
                </tr>
              </table>

              <p style="font-size:15px;line-height:1.6;color:#6C82A6;margin:0 0 28px;">Si no has sido tú quien creó esta cuenta, puedes ignorar este mensaje.</p>

              <a href="http://localhost:3000/" style="display:inline-block;padding:14px 28px;background:#087CF0;border-radius:11px;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">Ir al portal</a>
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
