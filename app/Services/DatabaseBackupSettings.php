<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class DatabaseBackupSettings
{
    private const FILE = 'settings.json';

    public function get(): array
    {
        $settings = array_merge($this->defaults(), $this->read()['sauvegarde'] ?? []);
        $settings['last_error'] = self::safeMailError($settings['last_error'] ?? null);

        return $settings;
    }

    public function update(array $settings): array
    {
        $data = $this->read();
        $data['sauvegarde'] = array_merge($this->defaults(), $data['sauvegarde'] ?? [], $settings);
        $this->write($data);

        return $data['sauvegarde'];
    }

    public function markAttempt(string $status, ?string $error = null, bool $fallbackAvailable = false): array
    {
        $configuration = $this->get();
        $now = now($configuration['timezone'] ?? null);

        return $this->update([
            'last_attempted_at' => $now->toIso8601String(),
            'last_status' => $status,
            'last_error' => $error,
            'last_completed_at' => $status === 'sent' ? $now->toIso8601String() : ($configuration['last_completed_at'] ?? null),
            'fallback_available' => $fallbackAvailable,
        ]);
    }

    public static function timezone(): string
    {
        $disk = Storage::disk('local');
        $contents = $disk->exists(self::FILE) ? $disk->get(self::FILE) : null;
        $settings = is_string($contents) ? json_decode($contents, true) : null;
        $timezone = is_array($settings) ? ($settings['sauvegarde']['timezone'] ?? null) : null;

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'Africa/Lubumbashi';
    }

    public static function safeMailError(?string $error): ?string
    {
        if ($error === null || trim($error) === '') {
            return null;
        }

        if (preg_match('/\\b(?:530|534|535)\\b|5\\.7\\.(?:0|8|9)|bad[\\s_-]*credentials|failed to authenticate|username and password not accepted|authentication (?:fail|reject|required)|application-specific password|authenticators?.*(login|plain|xoauth2)|xoauth2|auth.*credential/i', $error)) {
            if ($passwordIssue = self::gmailAppPasswordIssue()) {
                return $passwordIssue;
            }

            return 'Gmail refuse l’authentification SMTP. Vérifiez le compte MAIL_USERNAME et remplacez MAIL_PASSWORD par un mot de passe d’application Google (validation en deux étapes requise), puis exécutez php artisan config:clear.';
        }

        if (preg_match('/unsupported scheme|scheme .{0,30}not supported|scheme .{0,30}must be one of/i', $error)) {
            return 'Valeur MAIL_SCHEME invalide : pour Gmail sur le port 587, utilisez smtp ou laissez la valeur null (STARTTLS automatique). Pour le port 465, utilisez smtps. La valeur tls n’est pas valide.';
        }

        if (preg_match('/connection refused|connection timed out|timed out|could not connect|unable to connect|failed to connect|network is unreachable|stream_socket_client|dns|name or service not known|transportexception.*connect/i', $error)) {
            return 'Connexion au serveur SMTP impossible. Vérifiez MAIL_HOST=smtp.gmail.com, MAIL_PORT=587, MAIL_SCHEME=smtp (ou null) et l’accès réseau du serveur.';
        }

        if (preg_match('/550|5\\.1\\.1|recipient.*(reject|invalid|not found)|mailbox unavailable|address rejected/i', $error)) {
            return 'Le serveur SMTP a refusé l’adresse destinataire. Vérifiez l’adresse e-mail configurée dans la page Sauvegardes.';
        }

        if (preg_match('/552|message.*too large|attachment.*(large|size)|maximum message size|message size exceeds/i', $error)) {
            return 'Le serveur e-mail refuse la taille de la pièce jointe. Vérifiez la limite de taille des e-mails et réduisez la taille de la base sauvegardée.';
        }

        if (preg_match('/tls|ssl|certificate|encryption|starttls|crypto/i', $error)) {
            return 'La connexion sécurisée au serveur SMTP a échoué. Pour Gmail, vérifiez MAIL_PORT=587, MAIL_SCHEME=smtp (ou null pour STARTTLS automatique) et les certificats CA du serveur.';
        }

        if (preg_match('/mail(er)? n’est pas configuré|mail(er)? n.est pas configuré|smtp|transport/i', $error)) {
            return 'L’envoi SMTP a échoué. Pour Gmail, vérifiez MAIL_MAILER=smtp, MAIL_HOST=smtp.gmail.com, MAIL_PORT=587 et MAIL_SCHEME=smtp (ou null; ne mettez pas tls). Vérifiez aussi MAIL_USERNAME et le mot de passe d’application Google dans Backend/.env, puis exécutez php artisan config:clear.';
        }

        if (preg_match('/sqlstate|pdoexception|database|base de données|mysql|mariadb|queryexception|sql exception/i', $error)) {
            return 'La base de données a refusé la génération de la sauvegarde. Vérifiez la connexion MySQL/MariaDB et les droits de lecture.';
        }

        if ($passwordIssue = self::gmailAppPasswordIssue()) {
            return $passwordIssue;
        }

        return str_contains($error, 'uniquement pour MySQL et MariaDB')
            ? $error
            : 'Erreur non classée pendant la génération ou l’envoi. Vérifiez les paramètres SMTP et consultez la ligne « Database backup email failed » dans Backend/storage/logs/laravel.log.';
    }

    private static function gmailAppPasswordIssue(): ?string
    {
        if (config('mail.default') !== 'smtp' || config('mail.mailers.smtp.host') !== 'smtp.gmail.com') {
            return null;
        }

        $password = (string) config('mail.mailers.smtp.password', '');
        $compactPassword = preg_replace('/\\s+/', '', $password) ?? $password;
        if (strlen($compactPassword) !== 16) {
            return 'Le mot de passe Gmail chargé par Laravel comporte ' . strlen($compactPassword) . ' caractères; un mot de passe d’application Google en comporte 16. Générez-en un avec la validation en deux étapes, remplacez MAIL_PASSWORD dans Backend/.env sans espaces, puis redémarrez Laravel.';
        }

        if ($password !== $compactPassword) {
            return 'Le mot de passe d’application Google contient des espaces. Retirez-les de MAIL_PASSWORD dans Backend/.env, puis redémarrez Laravel.';
        }

        return null;
    }

    private function defaults(): array
    {
        return [
            'email' => '',
            'enabled' => false,
            'timezone' => 'Africa/Lubumbashi',
            'last_attempted_at' => null,
            'last_completed_at' => null,
            'last_status' => null,
            'last_error' => null,
            'fallback_available' => false,
        ];
    }

    private function read(): array
    {
        $contents = Storage::disk('local')->exists(self::FILE)
            ? Storage::disk('local')->get(self::FILE)
            : null;
        $settings = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($settings) ? $settings : [];
    }

    private function write(array $settings): void
    {
        Storage::disk('local')->put(self::FILE, json_encode(
            $settings,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
