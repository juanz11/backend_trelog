<?php

namespace App\Console\Commands;

use App\Mail\UserInvitationMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class TestMail extends Command
{
    protected $signature = 'app:test-mail';

    protected $description = 'Test mail sending';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->ask('Enter email address to send test to', 'uraharazamora@gmail.com');
        
        try {
            // Test with raw mail
            $this->info('Testing with raw mail...');
            Mail::raw('This is a test email from Laravel.', function ($message) use ($email) {
                $message->to($email)
                    ->subject('Test Email from Laravel (Raw)');
            });
            $this->info('Raw mail sent successfully to: ' . $email);
            
            // Test with invitation mailable
            $this->info('Testing with UserInvitationMail...');
            $frontendUrl = config('app.frontend_url');
            $invitationUrl = $frontendUrl . '/register?token=test123';
            
            Mail::to($email)->send(new UserInvitationMail(
                'Test User',
                $invitationUrl,
                'TR3SLOG',
                null,
                'es'
            ));
            
            $this->info('Invitation mail sent successfully to: ' . $email);
        } catch (\Exception $e) {
            $this->error('Failed to send test email: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
        }
    }
}
