<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Minutos de vigencia del código OTP. Debe coincidir con la expiración
     * usada en PasswordResetController para validar el código.
     */
    public const EXPIRATION_MINUTES = 15;

    public function __construct(
        public readonly User $user,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Código de recuperación de contraseña',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.password-reset',
            with: [
                'title'    => 'Recuperación de contraseña',
                'severity' => 'info',
                'userName' => $this->user->name,
                'code'     => $this->code,
                'minutes'  => self::EXPIRATION_MINUTES,
            ],
        );
    }
}
