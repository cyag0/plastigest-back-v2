<?php

namespace App\Mail;

use App\Models\User;
use App\Notifications\Contracts\NotificationTemplateInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly NotificationTemplateInterface $template,
        public readonly User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->template->getEmailSubject(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: $this->template->getEmailView(),
            with: array_merge($this->template->getEmailData(), [
                'recipient' => $this->recipient,
                'title'     => $this->template->getTitle(),
                'notificationMessage' => $this->template->getMessage(),
                'severity'  => $this->template->getSeverity(),
                'eventType' => $this->template->getEventType(),
            ]),
        );
    }
}
