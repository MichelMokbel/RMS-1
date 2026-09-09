<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StorefrontMenuOrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public array $snapshot,
        public string $audience,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->audience === 'admin'
                ? 'New paid advance menu order'
                : 'Your Layla Kitchen menu order is confirmed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.storefront_menu_order_confirmation',
            with: [
                'snapshot' => $this->snapshot,
                'audience' => $this->audience,
            ],
        );
    }
}
