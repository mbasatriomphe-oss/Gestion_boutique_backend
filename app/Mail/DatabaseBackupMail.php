<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DatabaseBackupMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $backupPath,
        private readonly string $filename,
        private readonly string $backupDate,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Sauvegarde du ' . $this->backupDate);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.database-backup',
            with: ['backupDate' => $this->backupDate],
        );
    }

    public function attachments(): array
    {
        return [
            \Illuminate\Mail\Mailables\Attachment::fromPath($this->backupPath)
                ->as($this->filename)
                ->withMime('application/sql'),
        ];
    }
}
