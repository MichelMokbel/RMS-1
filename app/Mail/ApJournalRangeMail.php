<?php

namespace App\Mail;

use App\Support\Reports\PdfExport;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class ApJournalRangeMail extends Mailable
{
    /** @param array<int, array<string, mixed>> $reports */
    public function __construct(
        public array $reports,
        public int $companyId,
        public string $company,
        public string $dateFrom,
        public string $dateTo,
        public Carbon $generatedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('AP Journal Reports: :company, :from to :to', [
            'company' => $this->company,
            'from' => $this->dateFrom,
            'to' => $this->dateTo,
        ]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ap-journal-range');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => PdfExport::output('reports.ap-journal-range-print', [
            'reports' => $this->reports,
            'company' => $this->company,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'generatedAt' => $this->generatedAt,
        ], 'a4', 'landscape'), $this->filename())->withMime('application/pdf')];
    }

    private function filename(): string
    {
        return 'AP-journals-'.$this->dateFrom.'-to-'.$this->dateTo.'.pdf';
    }
}
