<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Contacto TR3SLOG</title>
  <style>
    body { margin: 0; padding: 24px; background: #f4f7fb; font-family: Arial, sans-serif; color: #001B45; }
    .wrap { max-width: 600px; margin: 0 auto; }
    .box { background: #fff; border: 1px solid #DCE6F5; border-radius: 16px; padding: 32px; }
    h2 { margin: 0 0 24px; color: #087CF0; font-size: 22px; }
    .row { margin-bottom: 18px; }
    .label { font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: #6C82A6; font-weight: 700; margin-bottom: 6px; }
    .value { font-size: 15px; line-height: 1.6; }
    .message { background: #EEF4FC; border-radius: 12px; padding: 16px; white-space: pre-line; }
    .ref { display: inline-block; background: #EEF4FC; border: 1px solid #087CF0; color: #087CF0; padding: 8px 14px; border-radius: 8px; font-weight: 700; font-size: 14px; letter-spacing: .05em; }
    .footer { margin-top: 24px; font-size: 12px; color: #6C82A6; text-align: center; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="box">
      <h2>Estamos aquí para ayudarte</h2>
      <div class="row">
        <div class="label">Número de referencia</div>
        <div class="ref">{{ $data['reference'] ?? 'N/A' }}</div>
      </div>
      <div class="row">
        <div class="label">Nombre</div>
        <div class="value">{{ $data['name'] }}</div>
      </div>
      <div class="row">
        <div class="label">Email</div>
        <div class="value">{{ $data['email'] }}</div>
      </div>
      <div class="row">
        <div class="label">Asunto</div>
        <div class="value">{{ $data['subject'] }}</div>
      </div>
      <div class="row">
        <div class="label">Mensaje</div>
        <div class="message">{{ $data['message'] }}</div>
      </div>
    </div>
    <div class="footer">Este mensaje fue enviado desde el formulario de contacto de TR3SLOG.</div>
  </div>
</body>
</html>
