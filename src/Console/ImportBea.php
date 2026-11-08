<?php

namespace Nfgarching\Bea\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Nfgarching\Bea\Services\BeaImporter;

/**
 * Importiert beA-Exporte (ZIP) aus dem Eingangsordner oder aus angegebenen Dateien.
 */
class ImportBea extends Command
{
    protected $signature = 'bea:import
                            {paths?* : ZIP-Dateien im lokalen Dateisystem; ohne Angabe der Eingangsordner (bea.inbox)}
                            {--force : Bereits importierte Nachrichten erneut importieren}
                            {--keep : ZIPs im Eingangsordner nicht löschen}';

    protected $description = 'Importiert heruntergeladene beA-Nachrichten (ZIP)';

    public function handle(BeaImporter $importer): int
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
                $failed += $this->importOne($importer, $path, basename($path)) ? 0 : 1;
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $disk = Storage::disk(config('bea.inbox.disk', 'local'));
        $dir = trim((string) config('bea.inbox.path', 'bea-inbox'), '/');
        $zips = array_filter($disk->files($dir), fn ($f) => str_ends_with(strtolower($f), '.zip'));

        if ($zips === []) {
            $this->info('Keine ZIP-Dateien im Eingangsordner.');

            return self::SUCCESS;
        }

        foreach ($zips as $zip) {
            $local = tempnam(sys_get_temp_dir(), 'bea');
            try {
                $stream = $disk->readStream($zip);
                file_put_contents($local, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                if ($this->importOne($importer, $local, basename($zip))) {
                    if (config('bea.inbox.delete_after_import', true) && ! $this->option('keep')) {
                        $disk->delete($zip);
                    }
                } else {
                    $failed++;
                    $disk->move($zip, $dir.'/failed/'.basename($zip));
                }
            } finally {
                @unlink($local);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function importOne(BeaImporter $importer, string $path, string $name): bool
    {
        try {
            $result = $importer->import($path, $name, (bool) $this->option('force'));
        } catch (\Throwable $e) {
            $this->error("{$name}: ".$e->getMessage());

            return false;
        }

        foreach ($result['imported'] as $message) {
            $this->line(sprintf(
                '<info>✓</info> %s  beA %s  AZ %s  %s  (%d Anhänge, VHN %s)',
                $name,
                $message->bea_id ?? '–',
                $message->aktenzeichen ?? '–',
                $message->sender_name ?? '',
                $message->messageFiles->whereIn('role', ['attachment', 'signature'])->count(),
                $message->vhn_signature,
            ));
        }
        foreach ($result['skipped'] as $key) {
            $this->line("<comment>–</comment> {$name}  beA {$key} bereits importiert");
        }
        foreach (array_unique($result['warnings']) as $warning) {
            $this->warn("  {$warning}");
        }

        return true;
    }
}
