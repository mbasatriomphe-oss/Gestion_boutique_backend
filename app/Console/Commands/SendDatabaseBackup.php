<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupGenerator;
use App\Services\DatabaseBackupSender;
use App\Services\DatabaseBackupSettings;
use Illuminate\Console\Command;

class SendDatabaseBackup extends Command
{
    protected $signature = 'backup:database-email';

    protected $description = 'Génère et envoie la sauvegarde quotidienne de la base par e-mail.';

    public function handle(
        DatabaseBackupSettings $settings,
        DatabaseBackupGenerator $generator,
        DatabaseBackupSender $sender,
    ): int {
        $configuration = $settings->get();
        if (! ($configuration['enabled'] ?? false) || empty($configuration['email'])) {
            $this->info('La sauvegarde quotidienne par e-mail est désactivée ou aucune adresse n’est configurée.');

            return self::SUCCESS;
        }

        $result = $sender->send($settings, $generator);
        if (! $result['success']) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
