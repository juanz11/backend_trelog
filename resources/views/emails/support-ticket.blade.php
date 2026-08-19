<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Caso de soporte #{{ $ticket->id }}</title>
</head>
<body>
    <h2>Hemos recibido tu caso de soporte</h2>

    <p><strong>Asunto:</strong> {{ $ticket->subject }}</p>
    <p><strong>Mensaje:</strong></p>
    <p>{{ $ticket->message }}</p>

    <p>Un coordinador de soporte lo revisará y responderá dentro del próximo día hábil.</p>

    <p>Gracias,<br>Equipo TR3SLOG</p>
</body>
</html>
