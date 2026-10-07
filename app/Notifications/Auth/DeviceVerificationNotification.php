<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeviceVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $otp,
        public string $ipAddress,
        public string $userAgent
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Device Verification')
            ->greeting('Hello ' . $notifiable->business_name . ',')
            ->line('We detected a login attempt from a new device. For your security, please verify this login.')
            ->line('IP Address: ' . $this->ipAddress)
            ->line('Browser/Device: ' . $this->userAgent)
            ->line(new \Illuminate\Support\HtmlString('<div style="background-color: #f3f4f6; padding: 16px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 8px; border-radius: 8px; margin: 20px 0;">' . $this->otp . '</div>'))
            ->line('This code will expire in 5 minutes. If this was not you, please change your password immediately.');
    }
}
