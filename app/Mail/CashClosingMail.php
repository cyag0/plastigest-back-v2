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

    /**
     * PDF en base64. El correo se encola (Mail::queue) y el payload de la
     * cola se guarda como JSON, que no admite los bytes binarios del PDF:
     * con el binario crudo json_encode falla y el correo nunca se encola.
     * Es protected para no pasarlo a la vista del correo.
     */
    protected readonly string $pdfBase64;

    public function __construct(
        public readonly CashClosing $closing,
        string $pdfContent,
        public readonly ?string $recipientName = null,
    ) {
        $this->pdfBase64 = base64_encode($pdfContent);
    }

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
            Attachment::fromData(fn () => base64_decode($this->pdfBase64), "corte-caja-{$date}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
