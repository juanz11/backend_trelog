<?php

namespace App\Console\Commands;

use App\Mail\UserInvitationMail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('app:test-mail')]
#[Description('Test mail sending')]
class TestMail extends Command
{
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
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
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
