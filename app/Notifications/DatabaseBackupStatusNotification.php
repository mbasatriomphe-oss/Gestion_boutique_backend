<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DatabaseBackupStatusNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly array $details)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'database_backup',
            'title' => $this->details['success'] ? 'Sauvegarde quotidienne envoyée' : 'Échec de la sauvegarde quotidienne',
            'message' => $this->details['message'],
            'generated_at' => now()->toISOString(),
        ];
    }
}
