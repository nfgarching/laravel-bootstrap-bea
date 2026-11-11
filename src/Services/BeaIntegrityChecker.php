<?php

namespace Nfgarching\Bea\Services;

use Illuminate\Support\Facades\File as Filesystem;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Models\BeaVerification;
use Nfgarching\Bea\Parser\BeaArchive;
use Nfgarching\Bea\Parser\BeaMessageParser;

/**
 * Prüft eine eingelesene Nachricht erneut gegen die Original-ZIP und hält
 * das Ergebnis in der Prüfhistorie (bea_verifications) fest:
 *
 * 1. ZIP unverändert?            SHA-256 der ZIP gegen den Wert vom Einlesen
 * 2. Dateien unverändert?        SHA-256 jeder Datei gegen die erfasste Prüfsumme
 * 3. Herkunftsnachweis           VHN-Prüfsummen ↔ Dateien, Signatur vhn.xml.p7s
 */
class BeaIntegrityChecker
{
    public function __construct(
        private readonly VhnVerifier $verifier,
        private readonly BeaStorage $storage,
        private readonly BeaMessageParser $parser = new BeaMessageParser,
        private readonly BeaArchive $archive = new BeaArchive,
    ) {}

    public function check(BeaMessage $message, string $trigger = 'manual'): BeaVerification
    {
        $archive = $message->archive;
        if (! $archive || ! $this->storage->exists($archive)) {
            return $this->record($message, $trigger, [
                'archive_intact' => false,
                'files_intact' => null,
                'result' => ['error' => 'Original-ZIP fehlt auf der Disk'.($archive ? ': '.$archive->path : '.')],
            ]);
        }

        $work = sys_get_temp_dir().'/bea-check-'.bin2hex(random_bytes(6));
        $local = null;

        try {
            $local = $this->storage->localArchive($archive);
            $found = (string) hash_file('sha256', $local);
            $archiveIntact = hash_equals($archive->sha256, $found);

            $this->archive->extract($local, $work);
            $root = $work.($message->directory ? '/'.$message->directory : '');

            $files = [];
            $filesIntact = true;
            foreach ($message->messageFiles as $row) {
                $path = $work.'/'.$row->zip_path;
                $actual = is_file($path) ? (string) hash_file('sha256', $path) : null;
                $ok = $actual !== null && ($row->sha256 === null || hash_equals($row->sha256, $actual));
                $filesIntact = $filesIntact && $ok;
                $files[] = [
                    'name' => $row->name,
                    'role' => $row->role,
                    'expected' => $row->sha256,
                    'found' => $actual,
                    'ok' => $ok,
                ];
            }

            $data = is_dir($root) ? $this->parser->parse($work, (string) $message->directory) : null;
            $vhn = $data
                ? $this->verifier->verify(
                    $data->metaFiles['vhn'] ? $work.'/'.$data->metaFiles['vhn'] : null,
                    $data->metaFiles['vhn_signature'] ? $work.'/'.$data->metaFiles['vhn_signature'] : null,
                )
                : ['status' => 'missing'];
            $integrity = match (true) {
                ! $data || ! ($data->vhn['present'] ?? false) => 'missing',
                $data->integrity['complete'] => 'complete',
                default => 'incomplete',
            };

            // Aktueller Stand an der Nachricht, Verlauf in der Prüfhistorie
            $message->update(['integrity' => $integrity, 'vhn_signature' => $vhn['status']]);

            return $this->record($message, $trigger, [
                'archive_intact' => $archiveIntact,
                'files_intact' => $filesIntact,
                'integrity' => $integrity,
                'vhn_signature' => $vhn['status'],
                'result' => [
                    'archive' => ['sha256' => $archive->sha256, 'found' => $found],
                    'files' => $files,
                    'vhn' => $vhn,
                    'integrity' => $data?->integrity,
                    'warnings' => $data?->warnings ?? [],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->record($message, $trigger, [
                'archive_intact' => $archiveIntact ?? false,
                'files_intact' => false,
                'result' => ['error' => $e->getMessage()],
            ]);
        } finally {
            if ($local) {
                @unlink($local);
            }
            Filesystem::deleteDirectory($work);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(BeaMessage $message, string $trigger, array $attributes): BeaVerification
    {
        return $message->verifications()->create($attributes + [
            'trigger' => $trigger,
            'checked_by' => auth()->id(),
        ]);
    }
}
