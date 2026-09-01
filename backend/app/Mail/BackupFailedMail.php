<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ADR-039 decision 7 — sent to every active admin user when a backup
 * run or cleanup fails. See App\Services\Backup\BackupFailureAlerter.
 */
class BackupFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $context,
        public readonly string $failureMessage,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[{$this->context}] Backup failure — ".config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.backup-failed');
    }
}
