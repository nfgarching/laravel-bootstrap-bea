<?php

namespace Nfgarching\Bea\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Log;
use Nfgarching\Bea\Data\BeaMessageData;
use Nfgarching\Bea\Events\BeaFilesAccepted;
use Nfgarching\Bea\Events\BeaMessageImported;
use Nfgarching\Bea\Exceptions\InvalidBeaArchive;
use Nfgarching\Bea\Models\BeaArchive as BeaArchiveModel;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Models\BeaMessageFile;
use Nfgarching\Bea\Parser\BeaArchive;
use Nfgarching\Bea\Parser\BeaMessageParser;
use Nfgarching\Files\Events\NewFiles;
use Nfgarching\Files\Models\File;

/**
 * Import in zwei Schritten:
 *
 * ingest()  Einlesen – ZIP sicher entpacken, jede Nachricht auslesen und
 *           prüfen, ZIP unverändert auf der Disk „beas“ ablegen, alle
 *           enthaltenen Dateien mit Prüfsumme und Vorschlag erfassen.
 *           Ins Files-Package gelangt dabei nichts.
 *
 * accept()  Übernehmen – nur die von Hand gewählten Dateien werden aus der
 *           Original-ZIP geholt, gegen die erfasste Prüfsumme geprüft und an
 *           das Files-Package übergeben; dabei werden Akte und Namen
 *           zugeordnet. Signaturdateien (.p7s) bleiben in der ZIP.
 */
class BeaImporter
{
    private const string DISK = 'bea_import';

    public function __construct(
        private readonly VhnVerifier $verifier,
        private readonly BeaStorage $storage,
        private readonly BeaMessageParser $parser = new BeaMessageParser,
        private readonly BeaArchive $archive = new BeaArchive,
    ) {}

    /**
     * Liest eine ZIP ein. Bereits eingelesene Nachrichten (beA-ID/OSCI-ID)
     * werden übersprungen; sind alle bekannt, wird die ZIP nicht erneut abgelegt.
     *
     * @return array{archive: ?BeaArchiveModel, imported: list<BeaMessage>, skipped: list<string>, warnings: list<string>}
     */
    public function ingest(string $zipPath, ?string $originalName = null, string $source = 'upload', bool $force = false): array
    {
        $originalName = $this->safeName($originalName ?: basename($zipPath));
        $work = $this->makeWorkspace();

        try {
            $this->archive->extract($zipPath, $work.'/x');
            $directories = BeaMessageParser::messageDirectories($work.'/x');
            if ($directories === []) {
                throw new InvalidBeaArchive("Keine beA-Nachricht in {$originalName} gefunden.");
            }

            $result = ['archive' => null, 'imported' => [], 'skipped' => [], 'warnings' => []];
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

            $result['archive'] = $this->storage->storeArchive($zipPath, $originalName, $source);

            foreach ($pending as [$data, $existing]) {
                $result['imported'][] = $this->ingestMessage($work.'/x', $data, $existing, $result['archive']);
                array_push($result['warnings'], ...$data->warnings);
            }

            return $result;
        } finally {
            $this->removeWorkspace($work);
        }
    }

    private function ingestMessage(string $root, BeaMessageData $data, ?BeaMessage $existing, BeaArchiveModel $archive): BeaMessage
    {
        $vhn = $this->verifier->verify(
            $data->metaFiles['vhn'] ? $root.'/'.$data->metaFiles['vhn'] : null,
            $data->metaFiles['vhn_signature'] ? $root.'/'.$data->metaFiles['vhn_signature'] : null,
        );

        $payload = $data->toArray() + ['vhn_signature' => $vhn];
        $rows = $this->fileRows($root, $data);
        $integrity = match (true) {
            ! ($data->vhn['present'] ?? false) => 'missing',
            $data->integrity['complete'] => 'complete',
            default => 'incomplete',
        };

        $message = DB::transaction(function () use ($data, $existing, $archive, $payload, $vhn, $rows, $integrity) {
            $attributes = [
                'bea_archive_id' => $archive->id,
                'directory' => $data->directory,
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
                'integrity' => $integrity,
                'vhn_signature' => $vhn['status'],
                'data' => $payload,
            ];

            if ($existing) {
                // Erneutes Einlesen (--force): bereits übernommene Dateien behalten ihre Zeile
                $existing->messageFiles()->whereNull('file_id')->delete();
                $existing->update($attributes);
                $message = $existing;
            } else {
                $message = BeaMessage::create($attributes + [
                    'status' => BeaMessage::STATUS_INGESTED,
                    'imported_by' => auth()->id(),
                ]);
            }

            $kept = $message->messageFiles()->pluck('zip_path')->all();
            foreach ($rows as $row) {
                if (! in_array($row['zip_path'], $kept, true)) {
                    $message->messageFiles()->create($row);
                }
            }

            $message->verifications()->create([
                'trigger' => 'ingest',
                'archive_intact' => true,
                'files_intact' => true,
                'integrity' => $integrity,
                'vhn_signature' => $vhn['status'],
                'result' => [
                    'archive' => ['sha256' => $archive->sha256, 'size' => $archive->size],
                    'vhn' => $vhn,
                    'integrity' => $data->integrity,
                    'warnings' => $data->warnings,
                ],
                'checked_by' => auth()->id(),
            ]);

            return $message;
        });

        Log::info('beA-Nachricht '.($data->beaId ?? '?').' eingelesen (AZ '.($data->aktenzeichen ?? '–').', '.count($data->attachments).' Datei(en))');

        $message->load('messageFiles');

        BeaMessageImported::dispatch($message, $data->aktenzeichen, $data->aktenzeichenAbsender, $payload);

        return $message;
    }

    /**
     * Zeilen für bea_message_files: Anhänge mit Vorschlag, Signaturdateien
     * und die technischen Begleitdateien.
     *
     * @return list<array<string, mixed>>
     */
    private function fileRows(string $root, BeaMessageData $data): array
    {
        $classifier = BeaClassifier::fromConfig((array) config('bea.suggest', []));

        $signatures = [];
        foreach ($data->attachments as $attachment) {
            if (! empty($attachment['signature_of'])) {
                $signatures[$attachment['signature_of']] = $attachment['name'];
            }
        }

        $rows = [];
        foreach ($data->attachments as $attachment) {
            $isSignature = ! empty($attachment['signature_of']);
            $class = $isSignature
                ? ['category' => null, 'suggested' => false]
                : $classifier->classify($attachment['name'], $attachment['display_name'] ?? null, $attachment['document_class'] ?? null);

            $rows[] = [
                'role' => $isSignature ? BeaMessageFile::ROLE_SIGNATURE : BeaMessageFile::ROLE_ATTACHMENT,
                'name' => $attachment['name'],
                'zip_path' => $attachment['path'],
                'display_name' => $attachment['display_name'] ?? null,
                'document_class' => $attachment['document_class'] ?? null,
                'category' => $class['category'],
                'suggested' => $class['suggested'],
                'signed' => isset($signatures[$attachment['name']]),
                'signature_name' => $signatures[$attachment['name']] ?? null,
                'signature_of' => $attachment['signature_of'] ?? null,
                'sha256' => $attachment['sha256'] ?? null,
                'size' => $attachment['size'] ?? null,
                'vhn_covered' => $attachment['vhn_covered'] ?? null,
            ];
        }

        foreach ($data->metaFiles as $kind => $path) {
            if (! $path || ! is_file($root.'/'.$path)) {
                continue;
            }
            $name = basename($path);
            $rows[] = [
                'role' => BeaMessageFile::ROLE_META,
                'kind' => $kind,
                'name' => $name,
                'zip_path' => $path,
                'sha256' => hash_file('sha256', $root.'/'.$path) ?: null,
                'size' => filesize($root.'/'.$path) ?: 0,
                // Nur der XJustiz-Datensatz ist vom Herkunftsnachweis erfasst
                'vhn_covered' => $kind === 'xjustiz' ? in_array($name, $data->integrity['matched'] ?? [], true) : null,
            ];
        }

        return $rows;
    }

    /**
     * Übernimmt die gewählten Dateien einer eingelesenen Nachricht.
     *
     * Vor der Übergabe wird die Original-ZIP gegen ihre Prüfsumme vom
     * Einlesen geprüft und jede gewählte Datei gegen ihre erfasste Prüfsumme.
     *
     * @param  list<int>  $messageFileIds  IDs aus bea_message_files (nur role=attachment, noch nicht übernommen)
     * @param  list<Model>  $assignments  Akte, Namen …; die erste Zuordnung der Nachricht ist die Hauptzuordnung
     * @return array{files: list<File>, failed: list<string>}
     */
    public function accept(BeaMessage $message, array $messageFileIds, array $assignments = [], ?string $subject = null): array
    {
        foreach ($assignments as $model) {
            $message->assignTo($model);
        }
        $message->load('assignments');
        $primary = $message->primaryAssignment();

        $rows = $messageFileIds === [] ? collect() : $message->messageFiles()
            ->whereKey($messageFileIds)
            ->where('role', BeaMessageFile::ROLE_ATTACHMENT)
            ->whereNull('accepted_at')
            ->get();

        $files = [];
        $failed = [];

        if ($rows->isNotEmpty()) {
            $archive = $message->archive;
            if (! $archive) {
                throw new InvalidBeaArchive('Zur Nachricht ist keine Original-ZIP hinterlegt.');
            }

            $work = $this->makeWorkspace();
            $local = null;
            try {
                $local = $this->storage->localArchive($archive);
                if (! hash_equals($archive->sha256, (string) hash_file('sha256', $local))) {
                    throw new InvalidBeaArchive('Die Original-ZIP wurde seit dem Einlesen verändert – Übernahme abgebrochen.');
                }
                $this->archive->extract($local, $work.'/x');

                // Erst alles prüfen, dann übergeben
                foreach ($rows as $row) {
                    $path = $work.'/x/'.$row->zip_path;
                    if (! is_file($path) || ($row->sha256 && ! hash_equals($row->sha256, (string) hash_file('sha256', $path)))) {
                        throw new InvalidBeaArchive("Datei „{$row->name}“ entspricht nicht der beim Einlesen erfassten Prüfsumme.");
                    }
                }

                foreach ($rows as $row) {
                    $file = $this->importFile($row, $message, $primary?->assignable_type, $primary?->assignable_id);
                    if (! $file) {
                        $failed[] = $row->name;

                        continue;
                    }
                    $row->update(['file_id' => $file->id, 'accepted_at' => now()]);
                    $files[] = $file;
                }
            } finally {
                if ($local) {
                    @unlink($local);
                }
                $this->removeWorkspace($work);
            }

            $message->verifications()->create([
                'trigger' => 'accept',
                'archive_intact' => true,
                'files_intact' => true,
                'integrity' => $message->integrity,
                'vhn_signature' => $message->vhn_signature,
                'result' => [
                    'archive' => ['sha256' => $archive->sha256],
                    'accepted' => $rows->map(fn (BeaMessageFile $row) => ['name' => $row->name, 'sha256' => $row->sha256])->all(),
                    'failed' => $failed,
                ],
                'checked_by' => auth()->id(),
            ]);
        }

        $message->update([
            'status' => BeaMessage::STATUS_ACCEPTED,
            'accepted_at' => $message->accepted_at ?? now(),
            'accepted_by' => $message->accepted_by ?? auth()->id(),
        ]);

        $fileIds = array_map(fn (File $file) => $file->id, $files);

        // Übergabe an die Hauptzuordnung (z. B. Dokumente der Akte). Dateien, die
        // früher ohne Zuordnung übernommen wurden, werden jetzt nachgereicht.
        if ($primary) {
            $waiting = File::query()
                ->where('model_type', $message->getMorphClass())
                ->where('model_id', $message->id)
                ->whereKey($message->messageFiles()->whereNotNull('file_id')->pluck('file_id'))
                ->get();
            foreach ($waiting as $file) {
                $file->update(['model_type' => $primary->assignable_type, 'model_id' => $primary->assignable_id]);
            }
            $handover = array_values(array_unique([...$fileIds, ...$waiting->modelKeys()]));

            if ($handover !== []) {
                event(new NewFiles(
                    $handover,
                    $primary->assignable_type,
                    $primary->assignable_id,
                    $subject ?: $message->subject,
                    $this->handoverData($message),
                ));
            }
        }

        BeaFilesAccepted::dispatch(
            $message,
            $fileIds,
            $message->assignments->map(fn ($a) => ['type' => $a->assignable_type, 'id' => $a->assignable_id])->all(),
        );

        Log::info('beA-Nachricht '.($message->bea_id ?? '#'.$message->id).': '.count($fileIds).' Datei(en) übernommen');

        return ['files' => $files, 'failed' => $failed];
    }

    /**
     * Legt eine geprüfte Datei aus dem Arbeitsverzeichnis über das
     * Files-Package ab. Empfangene Dateien sind immer „Original Dokumente“:
     * das Original bleibt Hauptdatei, OCR-Text entsteht daneben. Liegt eine
     * abgesetzte Signatur in der ZIP, wird die Datei zusätzlich gesperrt.
     */
    private function importFile(BeaMessageFile $row, BeaMessage $message, ?string $modelType, int|string|null $modelId): ?File
    {
        $input = [
            'original' => true,
            'collection_name' => config('bea.collection', 'bea'),
            'model_type' => $modelType ?? $message->getMorphClass(),
            'model_id' => $modelId ?? $message->id,
        ];

        // Anzeigename aus XJustiz („Ladung zum Termin“), Dateiendung ergänzen
        if ($row->display_name && $row->display_name !== $row->name) {
            $extension = pathinfo($row->name, PATHINFO_EXTENSION);
            $input['name'] = $extension && ! str_ends_with(strtolower($row->display_name), '.'.strtolower($extension))
                ? $row->display_name.'.'.$extension
                : $row->display_name;
        }

        try {
            $file = File::importFromDisk(self::DISK, 'x/'.$row->zip_path, $input);
        } catch (\Throwable $e) {
            Log::warning('beA-Übernahme: Datei '.$row->name.' nicht abgelegt: '.$e->getMessage());

            return null;
        }

        $origin = [
            'message_id' => $message->id,
            'bea_id' => $message->bea_id,
            'name' => $row->name,
            'sha256' => $row->sha256,
            'category' => $row->category,
            'signed' => (bool) $row->signed,
            'signature' => $row->signature_name,
        ];
        $file->mergeJson('custom_properties', ['bea' => $origin]);

        if ($row->signed && method_exists($file, 'lock')) {
            $file->lock('bea-signature', 'Signierte Datei aus beA-Nachricht '.($message->bea_id ?? '#'.$message->id)
                .' – Signatur '.$row->signature_name.' liegt in der Original-ZIP.');
        }

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function handoverData(BeaMessage $message): array
    {
        return [
            'source' => 'bea',
            'bea_message_id' => $message->id,
            'bea_id' => $message->bea_id,
            'aktenzeichen' => $message->aktenzeichen,
            'aktenzeichen_absender' => $message->aktenzeichen_absender,
            'sender' => $message->sender_name,
            'received_at' => $message->received_at?->toIso8601String(),
            'eeb_requested' => (bool) $message->eeb_requested,
            'assignments' => $message->assignments->map(fn ($a) => ['type' => $a->assignable_type, 'id' => $a->assignable_id])->all(),
        ];
    }

    /**
     * Arbeitsverzeichnis als Disk, damit File::importFromDisk() es lesen kann.
     */
    private function makeWorkspace(): string
    {
        $work = sys_get_temp_dir().'/bea-import-'.bin2hex(random_bytes(6));
        Filesystem::ensureDirectoryExists($work, 0700);

        config(['filesystems.disks.'.self::DISK => ['driver' => 'local', 'root' => $work, 'throw' => false]]);
        app('filesystem')->forgetDisk(self::DISK);

        return $work;
    }

    private function removeWorkspace(string $work): void
    {
        Filesystem::deleteDirectory($work);
        app('filesystem')->forgetDisk(self::DISK);
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
