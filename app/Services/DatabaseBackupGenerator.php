<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DatabaseBackupGenerator
{
    public function generate(): string
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('La sauvegarde SQL est actuellement disponible uniquement pour MySQL et MariaDB.');
        }

        $path = tempnam(sys_get_temp_dir(), 'pos-database-backup-');
        if ($path === false) {
            throw new RuntimeException('Impossible de préparer le fichier de sauvegarde.');
        }

        try {
            $this->writeMysqlDump($connection->getPdo(), $path);
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return $path;
    }

    private function writeMysqlDump(\PDO $pdo, string $path): void
    {
        $file = fopen($path, 'wb');
        if ($file === false) {
            throw new RuntimeException('Impossible d’ouvrir le fichier temporaire de sauvegarde.');
        }

        $quoteIdentifier = static fn (string $identifier): string => '`' . str_replace('`', '``', $identifier) . '`';
        $write = static function (string $content) use ($file): void {
            $length = strlen($content);
            $written = 0;
            while ($written < $length) {
                $count = fwrite($file, substr($content, $written));
                if ($count === false || $count === 0) {
                    throw new RuntimeException('Échec de l’écriture du fichier de sauvegarde.');
                }
                $written += $count;
            }
        };

        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $write("-- Sauvegarde de la base de données\n");
            $write('-- Créée le ' . now()->toDateTimeString() . "\n\n");
            $write("SET FOREIGN_KEY_CHECKS=0;\n\n");

            $objects = $pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM);
            $tables = [];
            $views = [];
            foreach ($objects as $object) {
                if (($object[1] ?? '') === 'VIEW') {
                    $views[] = (string) $object[0];
                } else {
                    $tables[] = (string) $object[0];
                }
            }

            $triggers = $pdo->query('SHOW TRIGGERS')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($triggers as $trigger) {
                $write('DROP TRIGGER IF EXISTS ' . $quoteIdentifier((string) $trigger['Trigger']) . ";\n");
            }
            $write("\n");

            foreach ($tables as $table) {
                $quotedTable = $quoteIdentifier($table);
                $schema = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch(\PDO::FETCH_ASSOC);
                $createTable = $schema['Create Table'] ?? null;
                if (! is_string($createTable)) {
                    throw new RuntimeException("Impossible de lire la structure de la table {$table}.");
                }
                $write("DROP TABLE IF EXISTS {$quotedTable};\n{$createTable};\n\n");
            }

            foreach ($tables as $table) {
                $quotedTable = $quoteIdentifier($table);
                $columnRows = $pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(\PDO::FETCH_ASSOC);
                $columnRows = array_values(array_filter(
                    $columnRows,
                    static fn (array $column): bool => ! preg_match('/\\b(?:VIRTUAL|STORED)\\s+GENERATED\\b/i', (string) ($column['Extra'] ?? '')),
                ));
                $columns = array_map(static fn (array $column): string => (string) $column['Field'], $columnRows);
                $binaryColumns = [];
                foreach ($columnRows as $column) {
                    if (preg_match('/\\b(?:tinyblob|mediumblob|longblob|blob|binary|varbinary|bit|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)(?:\\b|\\()/i', (string) $column['Type'])) {
                        $binaryColumns[(string) $column['Field']] = true;
                    }
                }

                $quotedColumns = implode(', ', array_map($quoteIdentifier, $columns));
                $statement = $pdo->query('SELECT ' . ($quotedColumns !== '' ? $quotedColumns : '*') . " FROM {$quotedTable}");
                while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
                    $values = [];
                    foreach ($columns as $column) {
                        $value = $row[$column];
                        if ($value === null) {
                            $values[] = 'NULL';
                        } elseif (isset($binaryColumns[$column])) {
                            $values[] = '0x' . bin2hex((string) $value);
                        } else {
                            $quotedValue = $pdo->quote((string) $value);
                            if ($quotedValue === false) {
                                throw new RuntimeException("Impossible d’encoder une valeur de la table {$table}.");
                            }
                            $values[] = $quotedValue;
                        }
                    }

                    $write('INSERT INTO ' . $quoteIdentifier($table) . " ({$quotedColumns}) VALUES (" . implode(', ', $values) . ");\n");
                }
                $write("\n");
            }

            foreach ($triggers as $trigger) {
                $triggerName = (string) $trigger['Trigger'];
                $schema = $pdo->query('SHOW CREATE TRIGGER ' . $quoteIdentifier($triggerName))->fetch(\PDO::FETCH_ASSOC);
                $createTrigger = $schema['SQL Original Statement'] ?? $schema['Create Trigger'] ?? null;
                if (! is_string($createTrigger)) {
                    throw new RuntimeException("Impossible de lire la structure du trigger {$triggerName}.");
                }
                $createTrigger = preg_replace('/\\s+DEFINER=`[^`]+`@`[^`]+`/i', '', $createTrigger) ?? $createTrigger;
                $write("DELIMITER ;;\n{$createTrigger};;\nDELIMITER ;\n\n");
            }

            foreach ($views as $view) {
                $quotedView = $quoteIdentifier($view);
                $schema = $pdo->query("SHOW CREATE VIEW {$quotedView}")->fetch(\PDO::FETCH_ASSOC);
                $createView = $schema['Create View'] ?? null;
                if (is_string($createView)) {
                    $createView = preg_replace('/\\s+DEFINER=`[^`]+`@`[^`]+`/i', '', $createView) ?? $createView;
                    $write("DROP VIEW IF EXISTS {$quotedView};\n{$createView};\n\n");
                }
            }

            $write("SET FOREIGN_KEY_CHECKS=1;\n");
            if (! fflush($file)) {
                throw new RuntimeException('Impossible de finaliser le fichier de sauvegarde.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } finally {
            fclose($file);
        }
    }
}
