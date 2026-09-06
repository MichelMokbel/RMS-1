<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MembershipBookingConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public array $snapshot,
        public string $kind,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            'changed' => 'Your Daily Dish booking was updated',
            'cancelled' => 'Your Daily Dish booking was cancelled',
            default => 'Your Daily Dish meals are booked',
        });
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.membership_booking_confirmation',
            with: ['snapshot' => $this->snapshot, 'kind' => $this->kind],
        );
    }
}
