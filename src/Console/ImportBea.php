<?php

namespace Nfgarching\Bea\Console;

use Illuminate\Console\Command;
use Nfgarching\Bea\Services\BeaImporter;
use Nfgarching\Bea\Services\BeaStorage;

/**
 * Liest beA-Exporte (ZIP) aus dem Eingang (bea.inbox) oder aus
 * angegebenen Dateien ein. Es wird nur eingelesen: Welche Dateien ins
 * Files-Package übernommen werden, entscheidet man von Hand im Browser.
 */
class ImportBea extends Command
{
    protected $signature = 'bea:import
                            {paths?* : ZIP-Dateien im lokalen Dateisystem; ohne Angabe der Eingang der Disk (bea.inbox)}
                            {--force : Bereits eingelesene Nachrichten erneut einlesen}
                            {--keep : ZIPs im Eingang nicht löschen}';

    protected $description = 'Liest heruntergeladene beA-Nachrichten (ZIP) ein – ohne Übernahme der Dateien';

    public function handle(BeaImporter $importer, BeaStorage $storage): int
    {
        $failed = 0;
        $paths = $this->argument('paths');

        if ($paths) {
            foreach ($paths as $path) {
                if (! is_file($path)) {
                    $this->error("Datei nicht gefunden: {$path}");
                    $failed++;

                    continue;
                }
                $failed += $this->ingestOne($importer, $path, basename($path), 'console') ? 0 : 1;
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $zips = $storage->inboxFiles();
        if ($zips === []) {
            $this->info('Keine ZIP-Dateien im Eingang.');

            return self::SUCCESS;
        }

        foreach ($zips as $zip) {
            $local = null;
            try {
                $local = $storage->localCopy($storage->inboxDiskName(), $zip);
                if ($this->ingestOne($importer, $local, basename($zip), 'inbox')) {
                    // Das Original liegt jetzt unter archive/ – der Eingang wird geleert
                    if (! $this->option('keep')) {
                        $storage->deleteInboxFile($zip);
                    }
                } else {
                    $failed++;
                    $storage->moveToFailed($zip);
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error(basename($zip).': '.$e->getMessage());
            } finally {
                if ($local) {
                    @unlink($local);
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function ingestOne(BeaImporter $importer, string $path, string $name, string $source): bool
    {
        try {
            $result = $importer->ingest($path, $name, $source, (bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error("{$name}: ".$e->getMessage());

            return false;
        }

        foreach ($result['imported'] as $message) {
            $attachments = $message->messageFiles->where('role', 'attachment');
            $this->line(sprintf(
                '<info>✓</info> %s  beA %s  AZ %s  %s  (%d Datei(en), %d vorgeschlagen, VHN %s)',
                $name,
                $message->bea_id ?? '–',
                $message->aktenzeichen ?? '–',
                $message->sender_name ?? '',
                $attachments->count(),
                $attachments->where('suggested', true)->count(),
                $message->vhn_signature,
            ));
        }
        foreach ($result['skipped'] as $key) {
            $this->line("<comment>–</comment> {$name}  beA {$key} bereits eingelesen");
        }
        foreach (array_unique($result['warnings']) as $warning) {
            $this->warn("  {$warning}");
        }

        return true;
    }
}
