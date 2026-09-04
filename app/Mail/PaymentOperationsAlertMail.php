<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentOperationsAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $reference,
        public readonly string $reasonCode,
        public readonly string $operationsUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('SkipCash payment needs attention'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment_operations_alert');
    }
}
