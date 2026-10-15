<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SendOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $otp) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your Merchant Account')
            ->greeting('Hello ' . $notifiable->business_name . ',')
            ->line('Thank you for registering. Please use the following 6-digit code to verify your email address and activate your merchant console:')
            ->line(new \Illuminate\Support\HtmlString('<div style="background-color: #f3f4f6; padding: 16px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 8px; border-radius: 8px; margin: 20px 0;">' . $this->otp . '</div>'))
            ->line('This code will expire in 5 minutes. Requesting a new code invalidates the previous one.')
            ->line('If you did not create an account, no further action is required.');
    }
}
