<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendTeamInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token, public string $inviterName) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $url = "{$frontendUrl}/accept-invite?token={$this->token}&email=" . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject("You've been invited to join {$this->inviterName}")
            ->line("You have been invited to join the merchant console on Payflow.")
            ->action('Accept Invitation & Set Password', $url)
            ->line('This invitation link will expire in 24 hours.');
    }
}
