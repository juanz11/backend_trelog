<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password - TR3SLOG</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #0066cc, #4a90d9); padding: 30px; text-align: center;">
            <h1 style="margin: 0; color: #fff; font-size: 28px; font-weight: bold;">TR3SLOG</h1>
        </div>
        
        <!-- Content -->
        <div style="padding: 40px 30px;">
            <h2 style="margin-top: 0; color: #0066cc;">Reset Your Password</h2>
            
            <p>Hello {{ $userName ?? 'User' }},</p>
            
            <p>We received a request to reset your password for your TR3SLOG account. Click the button below to reset your password:</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ $resetLink }}" style="display: inline-block; background: #0066cc; color: #fff; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                    Reset Password
                </a>
            </div>
            
            <p>Or copy and paste this link into your browser:</p>
            <p style="background: #f5f5f5; padding: 15px; border-radius: 5px; word-break: break-all; font-family: monospace; font-size: 14px;">
                {{ $resetLink }}
            </p>
            
            <p style="font-size: 14px; color: #666;">This link will expire in 1 hour. If you didn't request this password reset, please ignore this email.</p>
            
            <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
            
            <p style="font-size: 14px; color: #666; margin: 0;">
                If you have any questions, please contact our support team.
            </p>
        </div>
        
        <!-- Footer -->
        <div style="background: #f5f5f5; padding: 20px; text-align: center; font-size: 12px; color: #666;">
            <p style="margin: 0;">&copy; {{ date('Y') }} TR3SLOG. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
