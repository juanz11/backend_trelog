<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $lang === 'en' ? 'Invitation' : 'Invitación' }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #0B1D3A 0%, #15294a 100%);
            padding: 40px 30px;
            text-align: center;
        }
        .logo {
            color: #D4A017;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.05em;
            margin-bottom: 10px;
        }
        .logo span {
            color: #fff;
        }
        .tagline {
            color: rgba(255, 255, 255, 0.8);
            font-size: 12px;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 24px;
            color: #0B1D3A;
            margin-bottom: 15px;
            font-weight: 600;
        }
        .message {
            color: #555;
            font-size: 15px;
            margin-bottom: 25px;
            line-height: 1.8;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #00AEEF 0%, #0095d9 100%);
            color: #fff;
            padding: 14px 32px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            margin: 20px 0;
            transition: transform 0.2s;
        }
        .cta-button:hover {
            transform: translateY(-2px);
        }
        .footer {
            background: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">TR3S<span>LOG</span></div>
            <div class="tagline">{{ $lang === 'en' ? 'Logistics Solutions' : 'Soluciones Logísticas' }}</div>
        </div>
        
        <div class="content">
            <div class="greeting">
                {{ $lang === 'en' ? 'Hello' : 'Hola' }}, {{ $userName }}!
            </div>
            
            <div class="message">
                @if($lang === 'en')
                    <p>You have been invited to join <strong>{{ $companyName }}</strong> on our logistics platform.</p>
                    <p>Click the button below to create your account and get started.</p>
                @else
                    <p>Has sido invitado a unirte a <strong>{{ $companyName }}</strong> en nuestra plataforma logística.</p>
                    <p>Haz clic en el botón de abajo para crear tu cuenta y comenzar.</p>
                @endif
            </div>
            
            <div style="text-align: center;">
                <a href="{{ $invitationUrl }}" class="cta-button">
                    {{ $lang === 'en' ? 'Create Account' : 'Crear Cuenta' }}
                </a>
            </div>
            
            <div class="message" style="margin-top: 25px; font-size: 13px; color: #777;">
                @if($lang === 'en')
                    <p>If you didn't expect this invitation, you can safely ignore this email.</p>
                @else
                    <p>Si no esperabas esta invitación, puedes ignorar este correo de forma segura.</p>
                @endif
            </div>
        </div>
        
        <div class="footer">
            {{ $lang === 'en' ? '© 2026 TR3SLOG. All rights reserved.' : '© 2026 TR3SLOG. Todos los derechos reservados.' }}
        </div>
    </div>
</body>
</html>
