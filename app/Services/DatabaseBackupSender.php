<?php

namespace App\Services;

use App\Mail\DatabaseBackupMail;
use App\Models\User;
use App\Notifications\DatabaseBackupStatusNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

class DatabaseBackupSender
{
    public function send(DatabaseBackupSettings $settings, DatabaseBackupGenerator $generator): array
    {
        $configuration = $settings->get();
        if (empty($configuration['email'])) {
            return ['success' => false, 'message' => 'Configurez une adresse e-mail avant de demander une sauvegarde par e-mail.', 'fallback_available' => false];
        }

        $path = null;
        $date = now($configuration['timezone'] ?? null)->format('d/m/Y');
        $filename = 'sauvegarde-' . now($configuration['timezone'] ?? null)->format('Y-m-d_H-i-s') . '.sql';

        try {
            $path = $generator->generate();
            if (in_array(config('mail.default'), ['log', 'array'], true)) {
                throw new RuntimeException('Le service e-mail n’est pas configuré pour un envoi réel. Configurez MAIL_MAILER et le serveur SMTP.');
            }

            Mail::to($configuration['email'])->send(new DatabaseBackupMail($path, $filename, $date));
            $settings->markAttempt('sent');
            $this->notifyAdministrators(true, "La sauvegarde du {$date} a été envoyée à l’adresse configurée.");

            return [
                'success' => true,
                'message' => "La sauvegarde du {$date} a été envoyée à {$configuration['email']}.",
                'fallback_available' => false,
            ];
        } catch (Throwable $exception) {
            $fallbackAvailable = false;
            if ($path !== null && is_file($path)) {
                try {
                    $fallbackAvailable = Storage::disk('local')->putFileAs(
                        'database-backups',
                        new File($path),
                        'latest.sql',
                    ) !== false;
                } catch (Throwable $storageException) {
                    report($storageException);
                }
            }

            $safeError = DatabaseBackupSettings::safeMailError($exception->getMessage())
                ?? 'Le service de messagerie n’a pas accepté la sauvegarde.';
            Log::error('Database backup email failed', [
                'exception_class' => $exception::class,
                'diagnostic' => $safeError,
            ]);
            $settings->markAttempt('failed', $safeError, $fallbackAvailable);
            $message = $fallbackAvailable
                ? "{$safeError} Une copie locale de secours est disponible dans l’administration."
                : "{$safeError} Aucune copie locale de secours n’a pu être conservée.";
            $this->notifyAdministrators(false, $message);

            return ['success' => false, 'message' => $message, 'fallback_available' => $fallbackAvailable];
        } finally {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function notifyAdministrators(bool $success, string $message): void
    {
        try {
            $admins = User::query()->where('role', 'admin')->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new DatabaseBackupStatusNotification([
                    'success' => $success,
                    'message' => $message,
                ]));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
