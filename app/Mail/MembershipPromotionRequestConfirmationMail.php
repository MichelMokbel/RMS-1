<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MembershipPromotionRequestConfirmationMail extends Mailable
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
                ? 'New promotional membership request'
                : 'Your Daily Dish membership request was received',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.membership_promotion_request_confirmation',
            with: [
                'snapshot' => $this->snapshot,
                'audience' => $this->audience,
            ],
        );
    }
}
