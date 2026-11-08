<?php

namespace Nfgarching\Bea\Parser;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Liest die beA-Exportansicht <ID>_export.html: alle beschrifteten Felder
 * (Absender, Betreff, Gesendet, Eingang Postfach …) sowie die Tabellen
 * Anhänge und Nachrichtenjournal.
 */
final class ExportHtmlParser
{
    /**
     * @return array{bea_id: ?string, fields: array<string, string>, attachments: list<array<string, string>>, journal: list<array<string, string>>}
     */
    public function parse(string $html): array
    {
        $html = preg_replace('/^\xEF\xBB\xBF/', '', $html) ?? $html;

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);

        $title = $this->clean($xpath->query('//title')?->item(0)?->textContent ?? '');
        preg_match('/Id\s+(\d+)/i', $title, $id);

        return [
            'bea_id' => $id[1] ?? null,
            'fields' => $this->fields($xpath),
            'attachments' => $this->table($xpath, 'attachmentTable'),
            'journal' => $this->table($xpath, 'messageJournalTable'),
        ];
    }

    /**
     * Beschriftung "Xyz:" → Text des folgenden Elements.
     *
     * @return array<string, string>
     */
    private function fields(DOMXPath $xpath): array
    {
        $fields = [];

        foreach ($xpath->query('//label') ?: [] as $label) {
            $name = rtrim($this->clean($label->textContent), ': ');
            if ($name === '' || isset($fields[$name])) {
                continue;
            }

            $value = null;
            if ($for = $label instanceof DOMElement ? $label->getAttribute('for') : '') {
                $target = $xpath->query('//*[@id="'.$for.'"]')?->item(0);
                $value = $target ? $this->clean($target->textContent) : null;
            }
            if ($value === null || $value === '') {
                $sibling = $label->nextSibling;
                while ($sibling && ! $sibling instanceof DOMElement) {
                    $sibling = $sibling->nextSibling;
                }
                $value = $sibling ? $this->clean($sibling->textContent) : '';
            }

            $fields[$name] = $value;
        }

        return $fields;
    }

    /**
     * @return list<array<string, string>>
     */
    private function table(DOMXPath $xpath, string $id): array
    {
        $table = $xpath->query('//*[@id="'.$id.'"]//table')?->item(0);
        if (! $table) {
            return [];
        }

        $headers = [];
        foreach ($xpath->query('.//thead//th', $table) ?: [] as $index => $th) {
            $headers[$index] = $this->clean($th->textContent) ?: 'col'.$index;
        }

        $rows = [];
        foreach ($xpath->query('.//tbody/tr', $table) ?: [] as $tr) {
            $cells = [];
            foreach ($xpath->query('./td', $tr) ?: [] as $index => $td) {
                $cells[$headers[$index] ?? 'col'.$index] = $this->clean($td->textContent);
            }
            // Symbolspalte ohne Text weglassen
            $cells = array_filter($cells, fn ($value, $key) => ! ($value === '' && str_starts_with((string) $key, 'col')), ARRAY_FILTER_USE_BOTH);
            if (array_filter($cells, fn ($v) => $v !== '')) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
