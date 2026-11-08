<?php

namespace Nfgarching\Bea\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Log;
use Nfgarching\Bea\Data\BeaMessageData;
use Nfgarching\Bea\Events\BeaMessageImported;
use Nfgarching\Bea\Exceptions\InvalidBeaArchive;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Parser\BeaArchive;
use Nfgarching\Bea\Parser\BeaMessageParser;
use Nfgarching\Files\Models\File;

/**
 * Importiert einen beA-Export (ZIP mit einer oder mehreren Nachrichten):
 *
 * 1. sicher entpacken, jede Nachricht auslesen
 * 2. bereits importierte Nachrichten (beA-ID/OSCI-ID) überspringen
 * 3. Herkunftsnachweis prüfen (Prüfsummen + Signatur)
 * 4. ZIP, Anhänge, Prüfprotokoll und Exportansicht als File ablegen
 *    (dabei läuft die Verarbeitungskette des Files-Packages: OCR, PDF/A …)
 * 5. je Nachricht BeaMessageImported auslösen
 */
class BeaImporter
{
    private const string DISK = 'bea_import';

    public function __construct(
        private readonly VhnVerifier $verifier,
        private readonly BeaMessageParser $parser = new BeaMessageParser,
        private readonly BeaArchive $archive = new BeaArchive,
    ) {}

    /**
     * @return array{imported: list<BeaMessage>, skipped: list<string>, warnings: list<string>}
     */
    public function import(string $zipPath, ?string $originalName = null, bool $force = false): array
    {
        $originalName = $this->safeName($originalName ?: basename($zipPath));
        $work = sys_get_temp_dir().'/bea-import-'.bin2hex(random_bytes(6));
        Filesystem::ensureDirectoryExists($work, 0700);

        // Arbeitsverzeichnis als Disk, damit File::importFromDisk() es lesen kann
        config(['filesystems.disks.'.self::DISK => ['driver' => 'local', 'root' => $work, 'throw' => false]]);
        app('filesystem')->forgetDisk(self::DISK);

        try {
            $this->archive->extract($zipPath, $work.'/x');
            $directories = BeaMessageParser::messageDirectories($work.'/x');
            if ($directories === []) {
                throw new InvalidBeaArchive("Keine beA-Nachricht in {$originalName} gefunden.");
            }

            $result = ['imported' => [], 'skipped' => [], 'warnings' => []];
            $pending = [];
            foreach ($directories as $directory) {
                $data = $this->parser->parse($work.'/x', $directory);
                $existing = $this->existing($data);
                if ($existing && ! $force) {
                    $result['skipped'][] = $data->key() ?? $directory;

                    continue;
                }
                $pending[] = [$data, $existing];
            }

            if ($pending === []) {
                return $result;
            }

            $zipFile = null;
            if (config('bea.store.zip', true)) {
                Filesystem::ensureDirectoryExists($work.'/zip', 0700);
                copy($zipPath, $work.'/zip/'.$originalName);
                $zipFile = $this->importFile('zip/'.$originalName, null);
            }

            foreach ($pending as [$data, $existing]) {
                $message = $this->importMessage($work, $data, $existing, $zipFile);
                $result['imported'][] = $message;
                array_push($result['warnings'], ...$data->warnings);
            }

            return $result;
        } finally {
            Filesystem::deleteDirectory($work);
            app('filesystem')->forgetDisk(self::DISK);
        }
    }

    private function importMessage(string $work, BeaMessageData $data, ?BeaMessage $existing, ?File $zipFile): BeaMessage
    {
        $root = $work.'/x/';
        $vhn = $this->verifier->verify(
            $data->metaFiles['vhn'] ? $root.$data->metaFiles['vhn'] : null,
            $data->metaFiles['vhn_signature'] ? $root.$data->metaFiles['vhn_signature'] : null,
        );

        $payload = $data->toArray() + ['vhn_signature' => $vhn];

        $message = DB::transaction(function () use ($data, $existing, $zipFile, $payload, $vhn) {
            $attributes = [
                'bea_id' => $data->beaId,
                'osci_id' => $data->osciId,
                'subject' => $data->subject,
                'sender_name' => $data->sender['name'] ?? $data->sender['display'] ?? null,
                'sender_safe_id' => $data->sender['safe_id'] ?? null,
                'sender_role' => $data->sender['role'] ?? null,
                'recipient_name' => $data->recipient['display'] ?? null,
                'recipient_safe_id' => $data->recipient['safe_id'] ?? null,
                'aktenzeichen' => $data->aktenzeichen,
                'aktenzeichen_absender' => $data->aktenzeichenAbsender,
                'sent_at' => $this->carbon($data->sentAt),
                'received_at' => $this->carbon($data->receivedAt),
                'intermediary_at' => $this->carbon($data->intermediaryAt),
                'secure_channel' => $data->secureChannel,
                'eeb_requested' => $data->eebRequested,
                'integrity' => match (true) {
                    ! ($data->vhn['present'] ?? false) => 'missing',
                    $data->integrity['complete'] => 'complete',
                    default => 'incomplete',
                },
                'vhn_signature' => $vhn['status'],
                'zip_file_id' => $zipFile?->id,
                'imported_by' => auth()->id(),
                'data' => $payload,
            ];

            if ($existing) {
                $existing->messageFiles()->delete();
                $existing->update($attributes);

                return $existing;
            }

            return BeaMessage::create($attributes);
        });

        $fileIds = [];
        foreach ($data->attachments as $attachment) {
            $role = empty($attachment['signature_of']) ? 'attachment' : 'signature';
            $file = $this->importFile('x/'.$attachment['path'], $message, $role === 'attachment' ? ($attachment['display_name'] ?? null) : null);
            $this->link($message, $file, $role, $attachment);
            if ($file) {
                $fileIds[] = $file->id;
            }
        }

        $metaFileIds = ['zip' => $zipFile?->id];
        foreach (['verification_report' => 'report', 'export' => 'export'] as $key => $role) {
            $path = $data->metaFiles[$key] ?? null;
            if ($path && config('bea.store.'.$key, true)) {
                $file = $this->importFile('x/'.$path, $message);
                $this->link($message, $file, $role, ['name' => basename($path)]);
                $metaFileIds[$role] = $file?->id;
            }
        }

        Log::info('beA-Nachricht '.($data->beaId ?? '?').' importiert (AZ '.($data->aktenzeichen ?? '–').', '.count($fileIds).' Anhang/Anhänge)');

        $message->load('messageFiles');

        BeaMessageImported::dispatch(
            $message,
            $data->aktenzeichen,
            $data->aktenzeichenAbsender,
            $payload,
            $fileIds,
            $metaFileIds,
        );

        return $message;
    }

    /**
     * Legt eine Datei aus dem Arbeitsverzeichnis über das Files-Package ab.
     * Empfangene Dateien sind nie „eigene Dokumente“ (Original bleibt erhalten).
     */
    private function importFile(string $relativePath, ?BeaMessage $message, ?string $displayName = null): ?File
    {
        $input = [
            'own' => false,
            'collection_name' => config('bea.collection', 'bea'),
        ];
        if ($message) {
            $input['model_type'] = $message::class;
            $input['model_id'] = $message->id;
        }
        // Anzeigename aus XJustiz („Ladung zum Termin“), Dateiendung ergänzen
        if ($displayName && $displayName !== basename($relativePath)) {
            $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
            $input['name'] = $extension && ! str_ends_with(strtolower($displayName), '.'.strtolower($extension))
                ? $displayName.'.'.$extension
                : $displayName;
        }

        try {
            return File::importFromDisk(self::DISK, $relativePath, $input);
        } catch (\Throwable $e) {
            Log::warning('beA-Import: Datei '.basename($relativePath).' nicht abgelegt: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function link(BeaMessage $message, ?File $file, string $role, array $info): void
    {
        $message->messageFiles()->create([
            'file_id' => $file?->id,
            'role' => $role,
            'name' => $info['name'],
            'display_name' => $info['display_name'] ?? null,
            'document_class' => $info['document_class'] ?? null,
            'sha256' => $info['sha256'] ?? null,
            'size' => $info['size'] ?? null,
            'vhn_covered' => $info['vhn_covered'] ?? null,
            'signature_of' => $info['signature_of'] ?? null,
        ]);
    }

    private function existing(BeaMessageData $data): ?BeaMessage
    {
        if ($data->beaId) {
            return BeaMessage::where('bea_id', $data->beaId)->first();
        }
        if ($data->osciId) {
            return BeaMessage::where('osci_id', $data->osciId)->first();
        }

        return null;
    }

    private function carbon(?\DateTimeImmutable $date): ?Carbon
    {
        return $date ? Carbon::instance($date)->setTimezone(config('app.timezone', 'UTC')) : null;
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'bea-nachricht.zip';

        return str_ends_with(strtolower($name), '.zip') ? $name : $name.'.zip';
    }
}
