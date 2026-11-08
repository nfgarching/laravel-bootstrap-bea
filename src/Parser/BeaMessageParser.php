<?php

namespace Nfgarching\Bea\Parser;

use DateTimeImmutable;
use DateTimeZone;
use Nfgarching\Bea\Data\BeaMessageData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Liest einen entpackten Nachrichtenordner aus einem beA-Export.
 *
 * Bewusst ohne Laravel-Abhängigkeiten, damit er sich isoliert testen lässt.
 */
final class BeaMessageParser
{
    public const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private readonly XJustizParser $xjustiz = new XJustizParser,
        private readonly VhnParser $vhn = new VhnParser,
        private readonly ExportHtmlParser $export = new ExportHtmlParser,
    ) {}

    /**
     * Ordner, die eine beA-Nachricht enthalten (Exportansicht, VHN oder
     * XJustiz-Datensatz), relativ zu $root; '' = Wurzel.
     *
     * @return list<string>
     */
    public static function messageDirectories(string $root): array
    {
        $dirs = [];
        foreach (self::files($root) as $relative) {
            $name = basename($relative);
            if (preg_match('/_export\.html$/i', $name) || in_array($name, ['vhn.xml', 'xjustiz_nachricht.xml'], true)) {
                $dir = dirname($relative);
                $dirs[$dir === '.' ? '' : $dir] = true;
            }
        }
        $dirs = array_keys($dirs);
        sort($dirs);

        return $dirs;
    }

    public function parse(string $root, string $directory = ''): BeaMessageData
    {
        $base = rtrim($root, '/').($directory !== '' ? '/'.$directory : '');
        $files = array_values(array_filter(self::files($base), fn ($f) => ! str_contains($f, '/')));
        $warnings = [];

        // Rollen der bekannten Begleitdateien
        $meta = ['osci' => null, 'export' => null, 'business_card' => null, 'verification_report' => null,
            'vhn' => null, 'vhn_signature' => null, 'technical_report' => null, 'xjustiz' => null];
        foreach ($files as $name) {
            $role = match (true) {
                (bool) preg_match('/^\d+\.xml$/', $name) => 'osci',
                (bool) preg_match('/_export\.html$/i', $name) => 'export',
                (bool) preg_match('/_BusinessCard\.html$/i', $name) => 'business_card',
                (bool) preg_match('/_VerificationReport\.pdf$/i', $name) => 'verification_report',
                $name === 'vhn.xml' => 'vhn',
                $name === 'vhn.xml.p7s' => 'vhn_signature',
                str_starts_with($name, 'technisches_pruefprotokoll') => 'technical_report',
                $name === 'xjustiz_nachricht.xml' => 'xjustiz',
                default => null,
            };
            if ($role && $meta[$role] === null) {
                $meta[$role] = $name;
            }
        }

        $read = fn (?string $name) => $name ? (string) file_get_contents($base.'/'.$name) : null;

        $export = ($html = $read($meta['export'])) !== null ? $this->export->parse($html) : ['bea_id' => null, 'fields' => [], 'attachments' => [], 'journal' => []];
        $xjustiz = ($xml = $read($meta['xjustiz'])) !== null ? $this->xjustiz->parse($xml) : ['present' => false];
        $vhn = ($xml = $read($meta['vhn'])) !== null ? $this->vhn->parse($xml) : ['present' => false, 'hashes' => []];
        $osci = ($raw = $read($meta['osci'])) !== null ? $this->osci($raw) : [];
        $fields = $export['fields'];

        if (! $meta['export']) {
            $warnings[] = 'Exportansicht (_export.html) fehlt – Eingangszeitpunkte unbekannt.';
        }
        if (! ($xjustiz['present'] ?? false)) {
            $warnings[] = 'Kein XJustiz-Strukturdatensatz (xjustiz_nachricht.xml).';
        }
        if (! ($vhn['present'] ?? false)) {
            $warnings[] = 'Kein Herkunftsnachweis (vhn.xml) – sicherer Übermittlungsweg nicht belegt.';
        }

        // Anhänge = alle übrigen Dateien; xjustiz_nachricht.xml zählt für den VHN mit
        $documents = [];
        foreach ($xjustiz['documents'] ?? [] as $document) {
            foreach ($document['files'] ?? [] as $file) {
                if (! empty($file['name'])) {
                    $documents[$file['name']] = $document;
                }
            }
        }

        $metaNames = array_filter(array_diff_key($meta, ['xjustiz' => true]));
        $attachments = [];
        $hashed = [];
        foreach ($files as $name) {
            if (in_array($name, $metaNames, true)) {
                continue;
            }
            $path = $base.'/'.$name;
            $binary = hash_file('sha256', $path, true);
            $hashed[base64_encode($binary)] = $name;

            if ($name === $meta['xjustiz']) {
                continue;
            }

            $signatureOf = preg_match('/^(.+)\.(p7s|pkcs7|sig)$/i', $name, $m) && in_array($m[1], $files, true) ? $m[1] : null;
            $attachments[] = array_filter([
                'name' => $name,
                'path' => ($directory !== '' ? $directory.'/' : '').$name,
                'size' => filesize($path),
                'sha256' => bin2hex($binary),
                'display_name' => $documents[$name]['display_name'] ?? null,
                'document_class' => $documents[$name]['document_class'] ?? null,
                'document_id' => $documents[$name]['id'] ?? null,
                'signature_of' => $signatureOf,
            ], fn ($v) => $v !== null);
        }

        $integrity = $this->integrity($vhn, $hashed, $attachments, $meta['xjustiz']);
        foreach ($attachments as $i => $attachment) {
            $attachments[$i]['vhn_covered'] = in_array($attachment['name'], $integrity['matched'], true);
        }
        if (($vhn['present'] ?? false) && ! $integrity['complete']) {
            $warnings[] = 'Prüfsummen des Herkunftsnachweises passen nicht vollständig zu den Dateien.';
        }

        $beaId = $export['bea_id']
            ?? (isset($meta['osci']) ? strstr($meta['osci'], '.', true) : null)
            ?: (preg_match('/Nachricht_(\d+)/', basename($base), $m) ? $m[1] : null);

        $sender = array_filter(($vhn['sender'] ?? []) + [
            'safe_id' => $xjustiz['sender']['safe_id'] ?? null,
            'role' => $vhn['role'] ?? null,
            'display' => $fields['Absender'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return new BeaMessageData(
            directory: $directory,
            beaId: $beaId ?: null,
            osciId: $fields['OSCI-Nachrichten-ID'] ?? $osci['message_id'] ?? null,
            subject: $fields['Betreff'] ?? $osci['subject'] ?? null,
            sender: $sender,
            recipient: array_filter([
                'display' => $fields['Empfänger'] ?? null,
                'safe_id' => $xjustiz['recipient']['safe_id'] ?? null,
            ]),
            aktenzeichen: $this->blankToNull($xjustiz['recipient']['aktenzeichen'] ?? null) ?? $this->blankToNull($fields['Aktenzeichen Empfänger'] ?? null),
            aktenzeichenAbsender: $this->blankToNull($xjustiz['sender']['aktenzeichen'] ?? null) ?? $this->blankToNull($fields['Aktenzeichen Sender'] ?? null),
            sentAt: $this->date($fields['Gesendet'] ?? null) ?? $this->date($xjustiz['created_at'] ?? null),
            receivedAt: $this->date($fields['Eingang Postfach'] ?? null),
            intermediaryAt: $this->date($fields['Eingang Intermediär'] ?? null),
            secureChannel: $vhn['secure_channel'] ?? null,
            eebRequested: (bool) ($xjustiz['eeb_requested'] ?? false),
            xjustiz: $xjustiz,
            vhn: $vhn,
            attachments: $attachments,
            metaFiles: array_map(fn ($name) => $name ? (($directory !== '' ? $directory.'/' : '').$name) : null, $meta),
            exportFields: $fields,
            journal: $export['journal'],
            integrity: $integrity,
            warnings: $warnings,
        );
    }

    /**
     * VHN-Prüfsummen ↔ Dateien. Signaturdateien (.p7s) ohne eigenen Eintrag
     * gelten nicht als Lücke, weil sie das signierte Dokument nur ergänzen.
     *
     * @param  array<string, mixed>  $vhn
     * @param  array<string, string>  $hashed  base64(sha256) => Dateiname
     * @param  list<array<string, mixed>>  $attachments
     * @return array<string, mixed>
     */
    private function integrity(array $vhn, array $hashed, array $attachments, ?string $xjustizFile): array
    {
        $matched = [];
        $unmatched = 0;
        foreach ($vhn['hashes'] ?? [] as $hash) {
            if (($hash['algorithm'] ?? 'SHA256') !== 'SHA256' || empty($hash['value'])) {
                $unmatched++;

                continue;
            }
            if (isset($hashed[$hash['value']])) {
                $matched[] = $hashed[$hash['value']];
            } else {
                $unmatched++;
            }
        }

        $uncovered = [];
        foreach ($attachments as $attachment) {
            if (! in_array($attachment['name'], $matched, true) && empty($attachment['signature_of'])) {
                $uncovered[] = $attachment['name'];
            }
        }
        if ($xjustizFile && ! in_array($xjustizFile, $matched, true)) {
            $uncovered[] = $xjustizFile;
        }

        $present = (bool) ($vhn['present'] ?? false);

        return [
            'vhn_hashes' => count($vhn['hashes'] ?? []),
            'matched' => $matched,
            'unmatched_hashes' => $unmatched,
            'uncovered' => $uncovered,
            'complete' => $present && $unmatched === 0 && $uncovered === [],
        ];
    }

    /**
     * OSCI-Umschlag: Nachrichten-ID (Base64) und Betreff.
     *
     * @return array{message_id?: string, subject?: string}
     */
    private function osci(string $raw): array
    {
        $result = [];
        if (preg_match('#<osci:MessageId>\s*([A-Za-z0-9+/=\s]+?)\s*</osci:MessageId>#', $raw, $m)) {
            $decoded = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
            $result['message_id'] = $decoded !== false && mb_check_encoding($decoded, 'UTF-8') ? $decoded : trim($m[1]);
        }
        if (preg_match('#<osci:Subject>\s*([^<]*?)\s*</osci:Subject>#', $raw, $m) && $m[1] !== '') {
            $result['subject'] = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return $result;
    }

    /**
     * "30.07.2026 10:26" (beA-Ansicht) oder ISO 8601 (XJustiz), Ortszeit Berlin.
     */
    private function date(?string $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $zone = new DateTimeZone(self::TIMEZONE);

        foreach (['d.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, $zone);
            if ($date !== false) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($value, $zone);
        } catch (\Exception) {
            return null;
        }
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Alle Dateien unterhalb von $root, relativ, ohne versteckte Dateien.
     *
     * @return list<string>
     */
    private static function files(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen(rtrim($root, '/'))), '/');
            if (str_starts_with(basename($relative), '.') || str_starts_with($relative, '__MACOSX/')) {
                continue;
            }
            $files[] = $relative;
        }
        sort($files);

        return $files;
    }
}
