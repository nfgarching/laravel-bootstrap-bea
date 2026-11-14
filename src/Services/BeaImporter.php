<?php

namespace Nfgarching\Bea\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Log;
use Nfgarching\Bea\Data\BeaFile;
use Nfgarching\Bea\Data\BeaMessageData;
use Nfgarching\Bea\Data\BeaZip;
use Nfgarching\Bea\Events\BeaFilesAccepted;
use Nfgarching\Bea\Events\BeaMessageImported;
use Nfgarching\Bea\Exceptions\InvalidBeaArchive;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Parser\BeaArchive;
use Nfgarching\Bea\Parser\BeaMessageParser;
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
 *           das Files-Package übergeben. Die Files gehören immer der
 *           beA-Nachricht (empfangener Stand als Beweis); die Anwendung
 *           erreicht sie über die Zuordnung der Nachricht (bea_messages.model,
 *           z. B. ein Dokument). Akte, Namen … gehen als Kontext mit dem
 *           Event BeaFilesAccepted an die Anwendung.
 *           Signaturdateien (.p7s) bleiben in der ZIP.
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
     * Sammel-ZIPs aus dem beA-Export mehrerer Nachrichten (Posteingang.zip mit
     * je einer Nachricht_….zip) werden aufgelöst: jede innere ZIP wird als
     * eigene Nachricht eingelesen und als eigenes Original abgelegt; die
     * Sammel-ZIP selbst wird nicht archiviert.
     *
     * @return array{archive: ?BeaZip, archives: list<BeaZip>, imported: list<BeaMessage>, skipped: list<string>, warnings: list<string>}
     */
    public function ingest(string $zipPath, ?string $originalName = null, string $source = 'upload', bool $force = false): array
    {
        return $this->ingestZip($zipPath, $originalName, $source, $force, nested: false);
    }

    /**
     * @return array{archive: ?BeaZip, archives: list<BeaZip>, imported: list<BeaMessage>, skipped: list<string>, warnings: list<string>}
     */
    private function ingestZip(string $zipPath, ?string $originalName, string $source, bool $force, bool $nested): array
    {
        $originalName = $this->safeName($originalName ?: basename($zipPath));
        $work = $this->makeWorkspace();

        try {
            $this->archive->extract($zipPath, $work.'/x');
            $directories = BeaMessageParser::messageDirectories($work.'/x');
            // Innere ZIPs nur eine Ebene tief (Sammel-ZIP → Nachricht_….zip)
            $innerZips = $nested ? [] : $this->innerZips($work.'/x');

            if ($directories === [] && $innerZips === []) {
                throw new InvalidBeaArchive("Keine beA-Nachricht in {$originalName} gefunden.");
            }

            $result = ['archive' => null, 'archives' => [], 'imported' => [], 'skipped' => [], 'warnings' => []];

            foreach ($innerZips as $inner) {
                try {
                    $part = $this->ingestZip($work.'/x/'.$inner, basename($inner), $source, $force, nested: true);
                } catch (\Throwable $e) {
                    $result['warnings'][] = $originalName.' → '.basename($inner).': '.$e->getMessage();
                    Log::warning('beA-Einlesen: '.$originalName.' → '.basename($inner).': '.$e->getMessage());

                    continue;
                } finally {
                    // ingestZip() der inneren ZIP hat die Disk auf ihr Arbeitsverzeichnis umgestellt
                    $this->useWorkspace($work);
                }
                $result['archive'] ??= $part['archive'];
                array_push($result['archives'], ...$part['archives']);
                array_push($result['imported'], ...$part['imported']);
                array_push($result['skipped'], ...$part['skipped']);
                array_push($result['warnings'], ...$part['warnings']);
            }

            if ($innerZips !== [] && $directories === [] && $result['imported'] === [] && $result['skipped'] === []) {
                throw new InvalidBeaArchive("Keine beA-Nachricht in {$originalName} gefunden: ".implode(' ', $result['warnings']));
            }

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

            $archive = $this->storage->storeArchive($zipPath, $originalName, $source);
            $result['archive'] ??= $archive;
            $result['archives'][] = $archive;

            foreach ($pending as [$data, $existing]) {
                $result['imported'][] = $this->ingestMessage($work.'/x', $data, $existing, $archive);
                array_push($result['warnings'], ...$data->warnings);
            }

            return $result;
        } finally {
            $this->removeWorkspace($work);
        }
    }

    /**
     * ZIP-Dateien im entpackten Archiv (relativ), sortiert.
     *
     * @return list<string>
     */
    private function innerZips(string $root): array
    {
        $zips = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'zip') {
                $zips[] = ltrim(substr($file->getPathname(), strlen($root)), '/');
            }
        }
        sort($zips);

        return $zips;
    }

    private function ingestMessage(string $root, BeaMessageData $data, ?BeaMessage $existing, BeaZip $archive): BeaMessage
    {
        $vhn = $this->verifier->verify(
            $data->metaFiles['vhn'] ? $root.'/'.$data->metaFiles['vhn'] : null,
            $data->metaFiles['vhn_signature'] ? $root.'/'.$data->metaFiles['vhn_signature'] : null,
        );

        $rows = $this->fileRows($root, $data);
        $payload = $data->toArray() + ['vhn_signature' => $vhn, 'files' => $rows];
        $integrity = match (true) {
            ! ($data->vhn['present'] ?? false) => 'missing',
            $data->integrity['complete'] => 'complete',
            default => 'incomplete',
        };

        $message = DB::transaction(function () use ($data, $existing, $archive, $payload, $vhn, $rows, $integrity) {
            $attributes = [
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
                'direction' => $data->direction,
                'dated_at' => $this->carbon($data->datedAt()),
                'secure_channel' => $data->secureChannel,
                'eeb_requested' => $data->eebRequested,
                'integrity' => $integrity,
                'vhn_signature' => $vhn['status'],
                'attachments_count' => count(array_filter($rows, fn (array $row) => $row['role'] === BeaFile::ROLE_ATTACHMENT)),
                'data' => $payload,
            ] + $archive->toColumns();

            if ($existing) {
                // Erneutes Einlesen (--force): übernommene Dateien bleiben Files der Nachricht
                $existing->update($attributes);
                $message = $existing;
            } else {
                // Zuordnung zunächst an den User, der einliest (Konsole: keine)
                $user = auth()->user();
                $message = BeaMessage::create($attributes + [
                    'status' => BeaMessage::STATUS_INGESTED,
                    'imported_by' => auth()->id(),
                    'model_type' => $user?->getMorphClass(),
                    'model_id' => $user?->getKey(),
                ]);
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

        $message->forgetMessageFiles();

        BeaMessageImported::dispatch($message, $data->aktenzeichen, $data->aktenzeichenAbsender, $payload);

        return $message;
    }

    /**
     * Einträge für data.files: Anhänge mit Vorschlag, Signaturdateien und
     * die technischen Begleitdateien.
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

        // Nie übernehmen (bea.exclude_extensions, z. B. xml): bleiben als Begleitdatei in der ZIP
        $excluded = array_map('strtolower', (array) config('bea.exclude_extensions', ['xml']));

        $rows = [];
        foreach ($data->attachments as $attachment) {
            $isSignature = ! empty($attachment['signature_of']);
            $isExcluded = ! $isSignature && in_array(strtolower(pathinfo($attachment['name'], PATHINFO_EXTENSION)), $excluded, true);
            $class = $isSignature || $isExcluded
                ? ['category' => null, 'suggested' => false]
                : $classifier->classify($attachment['name'], $attachment['display_name'] ?? null, $attachment['document_class'] ?? null);

            $rows[] = [
                'role' => match (true) {
                    $isSignature => BeaFile::ROLE_SIGNATURE,
                    $isExcluded => BeaFile::ROLE_META,
                    default => BeaFile::ROLE_ATTACHMENT,
                },
                'kind' => $isExcluded ? 'excluded' : null,
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
                'role' => BeaFile::ROLE_META,
                'kind' => preg_replace('/_\d+$/', '', (string) $kind),
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
     * Die Files gehören der Nachricht. $target ersetzt vorher ihre Zuordnung,
     * z. B. das Dokument, aus dem heraus übernommen wird; ist sie nur dem User
     * zugeordnet, legt die Anwendung im Listener auf BeaFilesAccepted das
     * Ziel an (z. B. ein neues Dokument) und trägt es mit assignTo() ein.
     *
     * @param  list<int>  $messageFileIds  BeaFile::$id aus data.files (nur übernehmbare Anlagen zählen)
     * @param  list<Model>  $context  Akte, Namen … für die Anwendung (Event BeaFilesAccepted), werden nicht gespeichert
     * @return array{files: list<File>, failed: list<string>}
     */
    public function accept(BeaMessage $message, array $messageFileIds, array $context = [], ?string $subject = null, ?Model $target = null): array
    {
        if ($target) {
            $message->assignTo($target);
        }
        $contextPairs = array_map(fn (Model $model) => ['type' => $model->getMorphClass(), 'id' => $model->getKey()], $context);

        $ids = array_map('intval', $messageFileIds);
        $rows = $ids === [] ? collect() : $message->forgetMessageFiles()->messageFiles()
            ->filter(fn (BeaFile $row) => in_array($row->id, $ids, true) && $row->isSelectable())
            ->values();

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
                    $file = $this->importFile($row, $message);
                    if (! $file) {
                        $failed[] = $row->name;

                        continue;
                    }
                    $files[] = $file;
                }
            } finally {
                if ($local) {
                    @unlink($local);
                }
                $this->removeWorkspace($work);
                $message->forgetMessageFiles();
            }

            $message->verifications()->create([
                'trigger' => 'accept',
                'archive_intact' => true,
                'files_intact' => true,
                'integrity' => $message->integrity,
                'vhn_signature' => $message->vhn_signature,
                'result' => [
                    'archive' => ['sha256' => $archive->sha256],
                    'accepted' => $rows->map(fn (BeaFile $row) => ['name' => $row->name, 'sha256' => $row->sha256])->all(),
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

        BeaFilesAccepted::dispatch($message, $fileIds, $contextPairs, $subject ?: $message->subject);

        Log::info('beA-Nachricht '.($message->bea_id ?? '#'.$message->id).': '.count($fileIds).' Datei(en) übernommen');

        return ['files' => $files, 'failed' => $failed];
    }

    /**
     * Legt eine geprüfte Datei aus dem Arbeitsverzeichnis über das
     * Files-Package ab. Empfangene Dateien sind immer „Original Dokumente“:
     * das Original bleibt Hauptdatei, OCR-Text entsteht daneben. Liegt eine
     * abgesetzte Signatur in der ZIP, wird die Datei zusätzlich gesperrt.
     */
    private function importFile(BeaFile $row, BeaMessage $message): ?File
    {
        $input = [
            'original' => true,
            'collection_name' => config('bea.collection', 'bea'),
            'model_type' => $message->getMorphClass(),
            'model_id' => $message->id,
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
            'zip_path' => $row->zip_path,
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
     * Arbeitsverzeichnis als Disk, damit File::importFromDisk() es lesen kann.
     */
    private function makeWorkspace(): string
    {
        $work = sys_get_temp_dir().'/bea-import-'.bin2hex(random_bytes(6));
        Filesystem::ensureDirectoryExists($work, 0700);
        $this->useWorkspace($work);

        return $work;
    }

    private function useWorkspace(string $work): void
    {
        config(['filesystems.disks.'.self::DISK => ['driver' => 'local', 'root' => $work, 'throw' => false]]);
        app('filesystem')->forgetDisk(self::DISK);
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
