<?php

namespace App\Mail;

use App\Support\SystemAlerts;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso de operación para los administradores del sistema (SystemAlerts).
 * No se encola a propósito: si lo que falla es la cola o el correo, un aviso
 * encolado fallaría también y generaría otro job fallido.
 */
class SystemAlertMail extends Mailable
{
    public function __construct(
        public readonly string $alertTitle,
        public readonly string $alertMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[' . config('app.name') . '] ' . $this->alertTitle,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.system-alert',
            with: [
                'title'           => $this->alertTitle,
                'severity'        => 'error',
                'serverUrl'       => config('app.url'),
                'occurredAt'      => now()->format('d/m/Y H:i'),
                'throttleMinutes' => SystemAlerts::THROTTLE_MINUTES,
            ],
        );
    }
}
