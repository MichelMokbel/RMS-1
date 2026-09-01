<?php

namespace App\Mail;

use App\Support\Reports\PdfExport;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class DailyApJournalMail extends Mailable
{
    public function __construct(public array $snapshot, public string $reportDate, public int $revision, public Carbon $generatedAt, public string $documentNumber) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('AP Journal :number: :company, :date', ['number' => $this->documentNumber, 'company' => $this->snapshot['company'], 'date' => $this->reportDate]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.daily-ap-journal');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => PdfExport::output('reports.ap-report-print', $this->snapshot + ['generatedAt' => $this->generatedAt, 'documentNumber' => $this->documentNumber], 'a4', 'landscape'),
            $this->documentNumber.'.pdf')->withMime('application/pdf')];
    }
}
