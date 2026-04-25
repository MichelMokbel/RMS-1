<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerPortalResetPassword extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = urlencode((string) ($notifiable->email ?? ''));
        $token = urlencode($this->token);
        $base = rtrim((string) config('customers.portal_base_url'), '/');
        $url = $base . '/orders/reset-password?token=' . $token . '&email=' . $email;

        return (new MailMessage)
            ->subject('Reset your Layla Kitchen password')
            ->line('You requested a password reset for your Layla Kitchen customer account.')
            ->action('Reset Password', $url)
            ->line('If you did not request a password reset, no further action is required.');
    }
}
