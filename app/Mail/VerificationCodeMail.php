<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerificationCodeMail extends Mailable
{
    use Queueable;

    public function __construct(public string $code, public string $purpose) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Seu código de confirmação — Planos São Francisco');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.verification-code');
    }
}
