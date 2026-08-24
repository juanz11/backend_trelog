<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tu cotización TR3SLOG</title>
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
              <h1 style="font-family:Montserrat,sans-serif;font-weight:800;font-size:26px;color:#001B45;margin:0 0 18px;">Hola, {{ $quote->client_name }}</h1>
              <p style="font-size:16px;line-height:1.7;color:#10233F;margin:0 0 20px;">Recibimos tu solicitud de cotización. Este es tu número de seguimiento:</p>

              <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0;background:#EEF4FC;border:1px solid #DCE6F5;border-radius:12px;width:100%;">
                <tr>
                  <td style="padding:18px 22px;text-align:center;">
                    <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#6C82A6;margin-bottom:8px;">Código de seguimiento</div>
                    <div style="font-family:Montserrat,sans-serif;font-weight:700;font-size:22px;color:#001B45;letter-spacing:.04em;">{{ $quote->tracking_code }}</div>
                  </td>
                </tr>
              </table>

              <p style="font-size:15px;line-height:1.6;color:#6C82A6;margin:0 0 20px;">Guárdalo. Puedes rastrear el estado de tu cotización en cualquier momento desde nuestro sitio.</p>
              <p style="font-size:15px;line-height:1.6;color:#6C82A6;margin:0 0 28px;">Origen: <strong>{{ $quote->origin }}</strong><br>Destino: <strong>{{ $quote->destination }}</strong></p>

              <a href="{{ config('app.frontend_url') }}/track" style="display:inline-block;padding:14px 28px;background:#087CF0;border-radius:11px;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">Rastrear cotización</a>
            </td>
          </tr>
          <tr>
            <td style="background:#F4F8FD;padding:24px 40px;text-align:center;font-size:12px;color:#6C82A6;border-top:1px solid #DCE6F5;">
              © TR3SLOG — Logistics Solutions
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
