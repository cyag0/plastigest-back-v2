<?php

namespace App\Mail;

use App\Models\CashClosing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CashClosingMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly CashClosing $closing,
        public readonly string $pdfContent,
        public readonly ?string $recipientName = null,
    ) {}

    public function envelope(): Envelope
    {
        $date = \Carbon\Carbon::parse($this->closing->closing_date)->format('d/m/Y');
        $location = $this->closing->location->name ?? '';

        return new Envelope(
            subject: trim("Corte de Caja {$location} - {$date}"),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cash-closing',
            with: [
                'title'         => 'Corte de Caja',
                'severity'      => 'info',
                'closing'       => $this->closing,
                'recipientName' => $this->recipientName,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $date = \Carbon\Carbon::parse($this->closing->closing_date)->format('Y-m-d');

        return [
            Attachment::fromData(fn () => $this->pdfContent, "corte-caja-{$date}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
