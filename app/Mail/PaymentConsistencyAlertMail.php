<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConsistencyAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<int, string> $issueCodes */
    public function __construct(
        public readonly string $ruleCode,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly string $episodeUuid,
        public readonly array $issueCodes,
        public readonly string $reviewUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Payment consistency issue needs attention'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.payment_consistency_alert');
    }
}
